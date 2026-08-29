<?php
/**
 * Plugin Name:       MS WordPress Abilities
 * Plugin URI:        https://miriamschwab.me/plugins/ms-wp-abilities
 * Description:       Registers WordPress core and custom abilities for MCP Adapter access, enabling AI agents to interact with this WordPress site.
 * Version:           1.11.1
 * Author:            Miriam Schwab
 * Author URI:        https://miriamschwab.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ms-wp-abilities
 * Domain Path:       /languages
 * Requires at least: 6.9
 * Requires PHP:      7.4
 *
 * @package MS_WP_Abilities
 */

// 6.9 required for the Abilities API (wp_register_ability, etc.).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSWPA_VERSION', '1.11.1' );
define( 'MSWPA_PREVIEW_EXPIRY_SECS', 600 );
define( 'MSWPA_ABILITIES_SNAPSHOT_OPTION', 'mswpa_abilities_snapshot' );
define( 'MSWPA_AUDIT_LOG_OPTION', 'mswpa_write_log' );

// Supporting logic lives in includes/ so each piece can be unit-tested without
// loading the plugin's hook registrations. See tests/.
require_once plugin_dir_path( __FILE__ ) . 'includes/rest-write-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ability-policy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ability-fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ability-audit-log.php';

add_action( 'init', 'mswpa_load_textdomain' );
/**
 * Load the plugin's translations.
 *
 * @return void
 */
function mswpa_load_textdomain() {
	load_plugin_textdomain( 'ms-wp-abilities', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// -------------------------------------------------------------------------
// Expose the WordPress core abilities via the MCP Adapter default server.
// -------------------------------------------------------------------------
add_filter( 'wp_register_ability_args', 'mswpa_enable_core_abilities_mcp_access', 10, 2 );
/**
 * Mark selected WordPress core abilities as publicly exposed to the MCP Adapter.
 *
 * Redundant on WordPress 7.1 with MCP Adapter 0.6.0+, but deliberately retained.
 * WordPress 7.1 sets a high-level `meta.public` flag on the core abilities itself,
 * and MCP Adapter 0.6.0 added the inheritance that makes that flag sufficient:
 * McpAbilityExposure::is_meta_public() honours an explicit `meta.mcp.public` when
 * present and otherwise falls back to `meta.public`. On that pairing this filter
 * only re-sets a value the two would already agree on -- verified against WP 7.1 +
 * MCP Adapter 0.6.1, where discover-abilities returns the same 85 abilities, the
 * three core ones included, with the filter on or off.
 *
 * It stays because neither half of that pairing is guaranteed by this plugin's
 * declared requirements. WordPress 6.9 and 7.0 -- still supported per the
 * `Requires at least: 6.9` header -- do not set `meta.public` on core abilities at
 * all, and MCP Adapter before 0.6.0 reads only `meta.mcp.public`, with no adapter
 * version floor declared in readme.txt. Without the filter, either case silently
 * drops the three core abilities from MCP discovery, contradicting the feature the
 * readme advertises. Remove this only alongside a floor of WP 7.1 and a declared
 * MCP Adapter 0.6.0 minimum.
 *
 * @param array  $args         Ability registration args.
 * @param string $ability_name Ability being registered.
 * @return array Filtered registration args.
 */
function mswpa_enable_core_abilities_mcp_access( array $args, string $ability_name ) {
	$core_abilities = array(
		'core/get-site-info',
		'core/get-user-info',
		'core/get-environment-info',
	);
	if ( in_array( $ability_name, $core_abilities, true ) ) {
		$args['meta']['mcp']['public'] = true;
	}
	return $args;
}

// -------------------------------------------------------------------------
// Ability guard rails.
// -------------------------------------------------------------------------
// Three layers that sit outside the individual ability registrations, so they
// stay correct for abilities added later without anyone remembering to wire
// them up. The registration filter works on every supported WordPress version;
// the two execute-time hooks are WordPress 7.1+ and simply never fire on 6.9 or
// 7.0. That is why each is a SECOND line of defense and never the only one:
// every ability keeps its own permission_callback, and the rest-write
// hard-block guard still runs inside the execute callback. These catch the case
// where one of those is wrong, not the case where it is absent.

add_filter( 'wp_register_ability_args', 'mswpa_force_no_rest_exposure', 10, 2 );

add_filter( 'wp_ability_validate_input', 'mswpa_validate_ability_input', 10, 3 );
/**
 * Run the rest-write hard-block guard at input-validation time.
 *
 * The guard already runs inside the rest-write execute callback, and that stays
 * the enforcing copy — it is what protects WordPress 6.9 and 7.0, where this
 * filter does not exist. Running it here as well, on 7.1+, buys two things:
 * the block happens before the permission check and before any execute callback
 * work, and it survives a future refactor of that callback.
 *
 * `wp_ability_validate_input` is the right hook for this rather than
 * `wp_ability_permission_result`, which is the other candidate: a WP_Error
 * returned from a permission filter is swallowed by WP_Ability::execute() and
 * replaced with a generic "does not have necessary permission" message, so the
 * agent would never learn WHICH rule it hit. A WP_Error returned here reaches
 * the caller intact, and the guard's specific reason is the useful part — it is
 * what tells the agent to stop retrying variants of a blocked route.
 *
 * @param true|WP_Error $is_valid     Validation result from schema validation.
 * @param mixed         $input        Input being validated.
 * @param string        $ability_name Ability being validated.
 * @return true|WP_Error Validation result.
 */
function mswpa_validate_ability_input( $is_valid, $input, $ability_name ) {
	if ( 'miriamschwab/rest-write' !== $ability_name || is_wp_error( $is_valid ) ) {
		return $is_valid;
	}
	if ( ! is_array( $input ) || empty( $input['route'] ) || empty( $input['method'] ) ) {
		// Missing required fields — let the execute callback report that.
		return $is_valid;
	}

	$route  = '/' . ltrim( sanitize_text_field( $input['route'] ), '/' );
	$method = strtoupper( sanitize_text_field( $input['method'] ) );
	$body   = ! empty( $input['body'] ) && is_array( $input['body'] ) ? $input['body'] : array();

	$blocked = mswpa_rest_write_blocked_reason( $route, $method, $body );
	if ( $blocked ) {
		return new WP_Error( 'rest_write_blocked', $blocked, array( 'status' => 403 ) );
	}
	return $is_valid;
}

add_filter( 'wp_ability_permission_result', 'mswpa_enforce_capability_floor', 10, 4 );
/**
 * Re-check the capability an ability declares in the policy table.
 *
 * Every ability already checks its own capability in its permission_callback,
 * so on a correct registration this changes nothing — which is the intent. It
 * exists for the incorrect one: an ability added with a copy-pasted callback
 * checking the wrong capability, or a callback that regressed to something
 * permissive, cannot execute while its policy-table entry still names the right
 * capability. AbilityPolicyTest asserts the table matches the registrations, so
 * the two cannot drift apart silently.
 *
 * Denies with `false` rather than a WP_Error on purpose: WP_Ability::execute()
 * discards a permission WP_Error's message and substitutes a generic one, so a
 * specific message here would be written and never read. Explanatory refusals
 * belong in mswpa_validate_ability_input() instead.
 *
 * Abilities outside this plugin's namespace are passed through untouched.
 *
 * @param bool|WP_Error $permission   Result from the ability's permission_callback.
 * @param string        $ability_name Ability being checked.
 * @param mixed         $input        Input for the permission check.
 * @param WP_Ability    $ability      The ability instance.
 * @return bool|WP_Error Permission result.
 */
function mswpa_enforce_capability_floor( $permission, $ability_name, $input, $ability ) {
	unset( $input, $ability );

	if ( ! mswpa_is_own_ability( $ability_name ) ) {
		return $permission;
	}
	// Already denied — nothing to add.
	if ( true !== $permission ) {
		return $permission;
	}

	$required = mswpa_required_capability_for( $ability_name );
	if ( null === $required ) {
		// Registered under this plugin's namespace but absent from the policy
		// table. Fail closed: an unclassified ability is one nothing here has
		// reviewed, and AbilityPolicyTest fails the build for exactly this.
		return false;
	}

	return current_user_can( $required );
}

add_action( 'wp_ability_invoked', 'mswpa_record_ability_invocation', 10, 2 );
/**
 * Record a write-ability invocation to the audit log.
 *
 * Fires before validation and before the permission check, so blocked and
 * denied calls are recorded too — those are the ones with no other trace. See
 * includes/ability-audit-log.php for what is and is not stored.
 *
 * @param string $ability_name Ability that was invoked.
 * @param mixed  $input        Raw input, before normalization.
 * @return void
 */
function mswpa_record_ability_invocation( $ability_name, $input ) {
	if ( ! is_string( $ability_name ) || ! mswpa_is_write_ability( $ability_name ) ) {
		return;
	}

	$log   = get_option( MSWPA_AUDIT_LOG_OPTION, array() );
	$log   = is_array( $log ) ? $log : array();
	$entry = mswpa_audit_entry( $ability_name, $input, get_current_user_id(), time() );

	update_option( MSWPA_AUDIT_LOG_OPTION, mswpa_audit_append( $log, $entry ), false );
}

// -------------------------------------------------------------------------
// Register custom ability category.
// -------------------------------------------------------------------------
add_action( 'wp_abilities_api_categories_init', 'mswpa_register_categories' );
/**
 * Register the "miriamschwab" ability category.
 *
 * The category slug itself stays "miriamschwab" — it's referenced by every
 * wp_register_ability() call below and is just a registry identifier, not a
 * site-specific requirement. Only the human-readable label/description were
 * site-specific; they're now named after the plugin instead, matching the
 * convention used by this author's other WordPress plugins.
 *
 * @return void
 */
function mswpa_register_categories() {
	wp_register_ability_category(
		'miriamschwab',
		array(
			'label'       => __( 'MS WordPress Abilities', 'ms-wp-abilities' ),
			'description' => __( 'Abilities registered by the MS WordPress Abilities plugin for AI agent site management.', 'ms-wp-abilities' ),
		)
	);
}

// -------------------------------------------------------------------------
// Register custom abilities.
// -------------------------------------------------------------------------
add_action( 'wp_abilities_api_init', 'mswpa_register_abilities' );
/**
 * Register all custom abilities in the "miriamschwab" category.
 *
 * @return void
 */
function mswpa_register_abilities() {

	// =========================================================================
	// POSTS
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-posts',
		array(
			'label'               => __( 'Get Posts', 'ms-wp-abilities' ),
			'description'         => __( 'Retrieve posts with optional filtering by status, post type, category, tag, and search term.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 100,
					),
					'status'        => array(
						'type'    => 'string',
						'enum'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'any' ),
						'default' => 'publish',
					),
					'post_type'     => array(
						'type'    => 'string',
						'default' => 'post',
					),
					'search'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'category_name' => array(
						'type'        => 'string',
						'description' => 'Category slug.',
						'default'     => '',
					),
					'tag'           => array(
						'type'        => 'string',
						'description' => 'Tag slug.',
						'default'     => '',
					),
					'orderby'       => array(
						'type'    => 'string',
						'enum'    => array( 'date', 'title', 'modified', 'ID', 'menu_order' ),
						'default' => 'date',
					),
					'order'         => array(
						'type'    => 'string',
						'enum'    => array( 'DESC', 'ASC' ),
						'default' => 'DESC',
					),
					'fields'        => array(
						'type'        => 'array',
						'description' => 'Subset of properties to return, to keep the response small. Omit for all of them. ID is always included so results stay actionable.',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'ID', 'post_title', 'post_status', 'post_type', 'post_date', 'post_modified', 'post_excerpt', 'categories', 'tags', 'featured_image', 'permalink', 'edit_link' ),
						),
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				$args  = array(
					'numberposts'   => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10,
					'post_status'   => isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : 'publish',
					'post_type'     => isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post',
					's'             => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
					'category_name' => isset( $input['category_name'] ) ? sanitize_text_field( $input['category_name'] ) : '',
					'tag'           => isset( $input['tag'] ) ? sanitize_text_field( $input['tag'] ) : '',
					'orderby'       => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'date',
					'order'         => isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ? 'ASC' : 'DESC',
				);
				$posts  = get_posts( $args );
				$fields = mswpa_requested_fields( $input['fields'] ?? null );
				// Each of these costs its own query per post, so skip the work
				// rather than computing a value that is about to be discarded.
				$wants  = static function ( string $field ) use ( $fields ): bool {
					return empty( $fields ) || in_array( $field, $fields, true );
				};
				$result = array();
				foreach ( $posts as $post ) {
					$row = array(
						'ID'             => $post->ID,
						'post_title'     => $post->post_title,
						'post_status'    => $post->post_status,
						'post_type'      => $post->post_type,
						'post_date'      => $post->post_date,
						'post_modified'  => $post->post_modified,
						'post_excerpt'   => $post->post_excerpt,
						'categories'     => $wants( 'categories' ) ? wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) : null,
						'tags'           => $wants( 'tags' ) ? wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) : null,
						'featured_image' => $wants( 'featured_image' ) && has_post_thumbnail( $post->ID ) ? wp_get_attachment_url( get_post_thumbnail_id( $post->ID ) ) : null,
						'permalink'      => $wants( 'permalink' ) ? get_permalink( $post->ID ) : null,
						'edit_link'      => $wants( 'edit_link' ) ? get_edit_post_link( $post->ID, 'raw' ) : null,
					);
					$result[] = mswpa_apply_fields( $row, $fields, array( 'ID' ) );
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/get-pages',
		array(
			'label'               => __( 'Get Pages', 'ms-wp-abilities' ),
			'description'         => __( 'Retrieve pages with optional filtering by status and search term. Returns parent/child hierarchy and menu order.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'status'   => array(
						'type'    => 'string',
						'enum'    => array( 'publish', 'draft', 'pending', 'private', 'any' ),
						'default' => 'any',
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => 'Parent page ID. 0 for top-level pages only.',
						'default'     => -1,
					),
					'fields'   => array(
						'type'        => 'array',
						'description' => 'Subset of properties to return, to keep the response small. Omit for all of them. ID is always included so results stay actionable.',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'ID', 'post_title', 'post_status', 'post_date', 'post_parent', 'menu_order', 'permalink', 'edit_link' ),
						),
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_pages' ),
			'execute_callback'    => function ( $input ) {
				$args = array(
					'numberposts' => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 50,
					'post_status' => isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : 'any',
					'post_type'   => 'page',
					's'           => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				);
				if ( isset( $input['parent'] ) && $input['parent'] >= 0 ) {
					$args['post_parent'] = absint( $input['parent'] );
				}
				$pages  = get_posts( $args );
				$fields = mswpa_requested_fields( $input['fields'] ?? null );
				$wants  = static function ( string $field ) use ( $fields ): bool {
					return empty( $fields ) || in_array( $field, $fields, true );
				};
				$result = array();
				foreach ( $pages as $page ) {
					$row = array(
						'ID'          => $page->ID,
						'post_title'  => $page->post_title,
						'post_status' => $page->post_status,
						'post_date'   => $page->post_date,
						'post_parent' => $page->post_parent,
						'menu_order'  => $page->menu_order,
						'permalink'   => $wants( 'permalink' ) ? get_permalink( $page->ID ) : null,
						'edit_link'   => $wants( 'edit_link' ) ? get_edit_post_link( $page->ID, 'raw' ) : null,
					);
					$result[] = mswpa_apply_fields( $row, $fields, array( 'ID' ) );
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/create-post',
		array(
			'label'               => __( 'Create Post', 'ms-wp-abilities' ),
			'description'         => __( 'Create a new WordPress post. Defaults to draft status. Pass markdown to auto-convert to Gutenberg blocks. Supports meta for Yoast SEO fields and any other post meta.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'title' ),
				'properties' => array(
					'title'      => array( 'type' => 'string' ),
					'markdown'   => array(
						'type'        => 'string',
						'description' => 'Post body as Markdown. Converted to native Gutenberg blocks server-side. Use this instead of content when the source is a markdown file. YAML frontmatter and a leading H1 are stripped automatically.',
					),
					'content'    => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Raw HTML post content. Ignored if markdown is provided.',
					),
					'excerpt'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'     => array(
						'type'    => 'string',
						'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
						'default' => 'draft',
					),
					'post_type'  => array(
						'type'    => 'string',
						'default' => 'post',
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Category names.',
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Tag names.',
					),
					'meta'       => array(
						'type'        => 'object',
						'description' => 'Post meta key-value pairs set on creation. Yoast SEO fields: _yoast_wpseo_focuskw, _yoast_wpseo_title, _yoast_wpseo_metadesc.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['title'] ) ) {
					return new WP_Error( 'missing_title', __( 'A post title is required.', 'ms-wp-abilities' ) );
				}
				$allowed  = array( 'draft', 'publish', 'pending', 'private' );
				$status   = isset( $input['status'] ) && in_array( $input['status'], $allowed, true ) ? $input['status'] : 'draft';
				if ( ! empty( $input['markdown'] ) ) {
					$post_content = mswpa_markdown_to_blocks( $input['markdown'] );
				} elseif ( isset( $input['content'] ) ) {
					$post_content = wp_kses_post( $input['content'] );
				} else {
					$post_content = '';
				}
				$postarr  = array(
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => $post_content,
					'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
					'post_status'  => $status,
					'post_type'    => isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post',
					'post_author'  => get_current_user_id(),
				);
				if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
					$meta_input = array();
					foreach ( $input['meta'] as $key => $value ) {
						$meta_input[ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
					}
					$postarr['meta_input'] = $meta_input;
				}
				$post_id = wp_insert_post( $postarr, true );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
					$cat_ids = array();
					foreach ( $input['categories'] as $cat_name ) {
						$term = get_term_by( 'name', sanitize_text_field( $cat_name ), 'category' );
						if ( $term ) {
							$cat_ids[] = $term->term_id;
						}
					}
					if ( $cat_ids ) {
						wp_set_post_categories( $post_id, $cat_ids );
					}
				}
				if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
					wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $input['tags'] ) );
				}
				return array(
					'ID'        => $post_id,
					'permalink' => get_permalink( $post_id ),
					'edit_link' => get_edit_post_link( $post_id, 'raw' ),
					'status'    => get_post_status( $post_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/patch-post-content',
		array(
			'label'               => __( 'Patch Post Content', 'ms-wp-abilities' ),
			'description'         => __( 'Surgical find-and-replace within post content. No preview/apply required. Use for targeted changes: fixing a typo, adding a link, updating a URL, inserting a sentence. Errors if find string not found. Returns replacement count.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'find', 'replace' ),
				'properties' => array(
					'post_id'     => array( 'type' => 'integer' ),
					'find'        => array(
						'type'        => 'string',
						'description' => 'Exact string to find in post content.',
					),
					'replace'     => array(
						'type'        => 'string',
						'description' => 'String to replace it with.',
					),
					'replace_all' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Replace all occurrences. Default: false (replace first only).',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return new WP_Error( 'missing_post_id', __( 'A post ID is required.', 'ms-wp-abilities' ) );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return new WP_Error( 'missing_find', __( 'A find string is required.', 'ms-wp-abilities' ) );
				}
				if ( ! isset( $input['replace'] ) ) {
					return new WP_Error( 'missing_replace', __( 'A replace string is required.', 'ms-wp-abilities' ) );
				}
				$post_id = absint( $input['post_id'] );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return new WP_Error( 'post_not_found', __( 'Post not found.', 'ms-wp-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'permission_denied', __( 'You do not have permission to edit this post.', 'ms-wp-abilities' ) );
				}
				$content     = $post->post_content;
				$find        = $input['find'];
				$replace     = $input['replace'];
				$replace_all = rest_sanitize_boolean( $input['replace_all'] ?? false );
				$count       = substr_count( $content, $find );
				if ( 0 === $count ) {
					return new WP_Error( 'string_not_found', __( 'The find string was not found in the post content.', 'ms-wp-abilities' ) );
				}
				if ( $replace_all ) {
					$new_content = str_replace( $find, $replace, $content );
				} else {
					$pos         = strpos( $content, $find );
					$new_content = substr_replace( $content, $replace, $pos, strlen( $find ) );
					$count       = 1;
				}
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'ID'                => $post_id,
					'replacements_made' => $count,
					'edit_link'         => get_edit_post_link( $post_id, 'raw' ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// preview-post-update — extended to support `meta` field (key-value pairs).
	wp_register_ability(
		'miriamschwab/preview-post-update',
		array(
			'label'               => __( 'Preview Post Update', 'ms-wp-abilities' ),
			'description'         => __( 'Stage a proposed post update server-side and return before/after diff. No changes are made. CORRECT USAGE: (1) Present proposed changes to the user as plain text and ask "Here\'s what I propose... should I go ahead?" (2) Wait for explicit user confirmation. (3) Only then call preview-post-update. (4) Immediately call apply-post-update.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'title'      => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'excerpt'    => array( 'type' => 'string' ),
					'status'     => array(
						'type' => 'string',
						'enum' => array( 'publish', 'draft', 'pending', 'private' ),
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Category names. Replaces existing.',
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Tag names. Replaces existing.',
					),
					'meta'       => array(
						'type'        => 'object',
						'description' => 'Post meta key-value pairs to update.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return new WP_Error( 'missing_post_id', __( 'A post ID is required.', 'ms-wp-abilities' ) );
				}
				$post_id = absint( $input['post_id'] );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return new WP_Error( 'post_not_found', __( 'Post not found.', 'ms-wp-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'permission_denied', __( 'You do not have permission to edit this post.', 'ms-wp-abilities' ) );
				}

				$current_categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
				$current_tags       = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

				$before   = array(
					'title'      => $post->post_title,
					'excerpt'    => $post->post_excerpt,
					'status'     => $post->post_status,
					'categories' => $current_categories,
					'tags'       => $current_tags,
				);
				$proposed = array();
				$changes  = array();

				if ( isset( $input['title'] ) && $input['title'] !== $post->post_title ) {
					$proposed['title'] = sanitize_text_field( $input['title'] );
					$changes[]         = 'title';
				}
				if ( isset( $input['excerpt'] ) && $input['excerpt'] !== $post->post_excerpt ) {
					$proposed['excerpt'] = sanitize_textarea_field( $input['excerpt'] );
					$changes[]           = 'excerpt';
				}
				if ( isset( $input['content'] ) ) {
					$proposed['content'] = wp_kses_post( $input['content'] );
					$changes[]           = 'content';
				}
				if ( isset( $input['status'] ) && $input['status'] !== $post->post_status ) {
					$proposed['status'] = sanitize_text_field( $input['status'] );
					$changes[]          = 'status';
				}
				if ( isset( $input['categories'] ) ) {
					$proposed['categories'] = array_map( 'sanitize_text_field', $input['categories'] );
					$changes[]              = 'categories';
				}
				if ( isset( $input['tags'] ) ) {
					$proposed['tags'] = array_map( 'sanitize_text_field', $input['tags'] );
					$changes[]        = 'tags';
				}
				if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
					$current_meta = array();
					foreach ( $input['meta'] as $key => $value ) {
						$current_meta[ sanitize_key( $key ) ] = get_post_meta( $post_id, sanitize_key( $key ), true );
					}
					$before['meta']   = $current_meta;
					$proposed['meta'] = array();
					foreach ( $input['meta'] as $key => $value ) {
						$proposed['meta'][ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
					}
					$changes[] = 'meta';
				}

				if ( empty( $changes ) ) {
					return new WP_Error( 'no_changes', __( 'No changes detected.', 'ms-wp-abilities' ) );
				}

				update_user_meta(
					get_current_user_id(),
					'_mswpa_pending_update_' . $post_id,
					array(
						'post_id'      => $post_id,
						'preview_time' => time(),
						'expires'      => time() + MSWPA_PREVIEW_EXPIRY_SECS,
						'raw_input'    => $input,
					)
				);

				return array(
					'post_id'      => $post_id,
					'title'        => $post->post_title,
					'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
					'changes'      => $changes,
					'before'       => $before,
					'proposed'     => $proposed,
					'instructions' => 'Show the user this before/after comparison. Only call miriamschwab/apply-post-update (with this post_id) after they confirm.',
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/apply-post-update',
		array(
			'label'               => __( 'Apply Post Update', 'ms-wp-abilities' ),
			'description'         => __( 'Apply a pending post update staged by preview-post-update. Call immediately after the user confirms the proposed change. Takes post_id; multiple staged updates can be applied independently.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return new WP_Error( 'missing_post_id', __( 'A post ID is required.', 'ms-wp-abilities' ) );
				}
				$post_id  = absint( $input['post_id'] );
				$user_id  = get_current_user_id();
				$meta_key = '_mswpa_pending_update_' . $post_id;
				$pending  = get_user_meta( $user_id, $meta_key, true );

				if ( empty( $pending ) || ! is_array( $pending ) ) {
					return new WP_Error(
						'no_pending_update',
						sprintf(
							/* translators: %d: post ID. */
							__( 'No pending update found for post %d. Call preview-post-update first.', 'ms-wp-abilities' ),
							$post_id
						)
					);
				}
				if ( time() > $pending['expires'] ) {
					delete_user_meta( $user_id, $meta_key );
					return new WP_Error( 'preview_expired', __( 'Pending update expired. Call preview-post-update again.', 'ms-wp-abilities' ) );
				}

				$post = get_post( $post_id );
				if ( ! $post ) {
					delete_user_meta( $user_id, $meta_key );
					return new WP_Error( 'post_not_found', __( 'Post not found.', 'ms-wp-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'permission_denied', __( 'You do not have permission to edit this post.', 'ms-wp-abilities' ) );
				}

				$raw     = $pending['raw_input'];
				$updated = array();
				$args    = array( 'ID' => $post_id );

				if ( isset( $raw['title'] ) && '' !== $raw['title'] ) {
					$args['post_title'] = sanitize_text_field( $raw['title'] );
					$updated[] = 'title';
				}
				if ( isset( $raw['content'] ) ) {
					$args['post_content'] = wp_kses_post( $raw['content'] );
					$updated[] = 'content';
				}
				if ( isset( $raw['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_textarea_field( $raw['excerpt'] );
					$updated[] = 'excerpt';
				}
				if ( isset( $raw['status'] ) ) {
					$allowed = array( 'publish', 'draft', 'pending', 'private' );
					if ( in_array( $raw['status'], $allowed, true ) ) {
						$args['post_status'] = $raw['status'];
						$updated[] = 'status';
					}
				}
				if ( count( $args ) > 1 ) {
					$result = wp_update_post( $args, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ) {
					$cat_ids = array();
					foreach ( $raw['categories'] as $cat_name ) {
						$cat_name = sanitize_text_field( $cat_name );
						$term = get_term_by( 'name', $cat_name, 'category' );
						if ( $term ) {
							$cat_ids[] = $term->term_id;
						} else {
							$new_term = wp_insert_term( $cat_name, 'category' );
							if ( ! is_wp_error( $new_term ) ) {
								$cat_ids[] = $new_term['term_id'];
							}
						}
					}
					wp_set_post_categories( $post_id, $cat_ids );
					$updated[] = 'categories';
				}
				if ( isset( $raw['tags'] ) && is_array( $raw['tags'] ) ) {
					wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $raw['tags'] ) );
					$updated[] = 'tags';
				}
				if ( isset( $raw['meta'] ) && is_array( $raw['meta'] ) ) {
					foreach ( $raw['meta'] as $key => $value ) {
						update_post_meta( $post_id, sanitize_key( $key ), sanitize_textarea_field( (string) $value ) );
					}
					$updated[] = 'meta';
				}

				delete_user_meta( $user_id, $meta_key );

				return array(
					'ID'        => $post_id,
					'updated'   => $updated,
					'permalink' => get_permalink( $post_id ),
					'edit_link' => get_edit_post_link( $post_id, 'raw' ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/trash-post',
		array(
			'label'               => __( 'Trash Post', 'ms-wp-abilities' ),
			'description'         => __( 'Move a post or page to the trash. Reversible from wp-admin.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'delete_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return new WP_Error( 'missing_post_id', __( 'A post ID is required.', 'ms-wp-abilities' ) );
				}
				$post_id = absint( $input['post_id'] );
				if ( ! current_user_can( 'delete_post', $post_id ) ) {
					return new WP_Error( 'permission_denied', __( 'You do not have permission to delete this post.', 'ms-wp-abilities' ) );
				}
				$result = wp_trash_post( $post_id );
				if ( ! $result ) {
					return new WP_Error( 'trash_failed', __( 'Failed to trash post.', 'ms-wp-abilities' ) );
				}
				return array(
					'ID'     => $post_id,
					'status' => 'trashed',
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/get-post-meta',
		array(
			'label'               => __( 'Get Post Meta', 'ms-wp-abilities' ),
			'description'         => __( 'Read post meta for a given post. Returns all meta by default, or specific keys if provided.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'keys'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Specific meta keys to retrieve. Omit for all meta.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return new WP_Error( 'missing_post_id', __( 'A post ID is required.', 'ms-wp-abilities' ) );
				}
				$post_id = absint( $input['post_id'] );
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'post_not_found', __( 'Post not found.', 'ms-wp-abilities' ) );
				}
				if ( ! empty( $input['keys'] ) && is_array( $input['keys'] ) ) {
					$result = array();
					foreach ( $input['keys'] as $key ) {
						$result[ sanitize_key( $key ) ] = get_post_meta( $post_id, sanitize_key( $key ), true );
					}
					return $result;
				}
				$all_meta = get_post_meta( $post_id );
				$result   = array();
				foreach ( $all_meta as $key => $values ) {
					$result[ $key ] = count( $values ) === 1 ? $values[0] : $values;
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/get-post-types',
		array(
			'label'               => __( 'Get Post Types', 'ms-wp-abilities' ),
			'description'         => __( 'List all registered public post types.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function () {
				$post_types = get_post_types( array( 'public' => true ), 'objects' );
				$result = array();
				foreach ( $post_types as $pt ) {
					$result[] = array(
						'name'         => $pt->name,
						'label'        => $pt->label,
						'description'  => $pt->description,
						'hierarchical' => $pt->hierarchical,
						'has_archive'  => $pt->has_archive,
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// TAXONOMY
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-categories',
		array(
			'label'               => __( 'Get Categories', 'ms-wp-abilities' ),
			'description'         => __( 'Retrieve all categories with slugs, post counts, and parent relationships.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				$categories = get_categories(
					array(
						'hide_empty' => rest_sanitize_boolean( $input['hide_empty'] ?? false ),
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				$result = array();
				foreach ( $categories as $cat ) {
					$result[] = array(
						'term_id'     => $cat->term_id,
						'name'        => $cat->name,
						'slug'        => $cat->slug,
						'count'       => $cat->count,
						'parent'      => $cat->parent,
						'description' => $cat->description,
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/get-tags',
		array(
			'label'               => __( 'Get Tags', 'ms-wp-abilities' ),
			'description'         => __( 'Retrieve all post tags with slugs and post counts.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				$tags   = get_tags(
					array(
						'hide_empty' => rest_sanitize_boolean( $input['hide_empty'] ?? false ),
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				$result = array();
				foreach ( $tags as $tag ) {
					$result[] = array(
						'term_id'     => $tag->term_id,
						'name'        => $tag->name,
						'slug'        => $tag->slug,
						'count'       => $tag->count,
						'description' => $tag->description,
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/create-term',
		array(
			'label'               => __( 'Create Term', 'ms-wp-abilities' ),
			'description'         => __( 'Create a new category or tag.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name', 'taxonomy' ),
				'properties' => array(
					'name'        => array( 'type' => 'string' ),
					'taxonomy'    => array(
						'type' => 'string',
						'enum' => array( 'category', 'post_tag' ),
					),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'Parent term ID. Categories only.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['name'] ) || empty( $input['taxonomy'] ) ) {
					return new WP_Error( 'missing_fields', __( 'Name and taxonomy are required.', 'ms-wp-abilities' ) );
				}
				$taxonomy = in_array( $input['taxonomy'], array( 'category', 'post_tag' ), true ) ? $input['taxonomy'] : 'category';
				$args     = array();
				if ( ! empty( $input['slug'] ) ) {
					$args['slug'] = sanitize_title( $input['slug'] );
				}
				if ( ! empty( $input['description'] ) ) {
					$args['description'] = sanitize_textarea_field( $input['description'] );
				}
				if ( ! empty( $input['parent'] ) && 'category' === $taxonomy ) {
					$args['parent'] = absint( $input['parent'] );
				}
				$result = wp_insert_term( sanitize_text_field( $input['name'] ), $taxonomy, $args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$term = get_term( $result['term_id'], $taxonomy );
				return array(
					'term_id'  => $term->term_id,
					'name'     => $term->name,
					'slug'     => $term->slug,
					'taxonomy' => $taxonomy,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// MEDIA
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-media',
		array(
			'label'               => __( 'Get Media', 'ms-wp-abilities' ),
			'description'         => __( 'List media library items with optional filtering by mime type and search.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => 'e.g. image, image/jpeg, video, application/pdf',
					),
					'search'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'fields'    => array(
						'type'        => 'array',
						'description' => 'Subset of properties to return, to keep the response small. Omit for all of them. ID is always included so results stay actionable.',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'ID', 'title', 'caption', 'alt', 'url', 'mime_type', 'date', 'width', 'height', 'edit_link' ),
						),
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'upload_files' ),
			'execute_callback'    => function ( $input ) {
				$args = array(
					'numberposts' => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20,
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					's'           => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
				);
				if ( ! empty( $input['mime_type'] ) ) {
					$args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
				}
				$attachments = get_posts( $args );
				$fields      = mswpa_requested_fields( $input['fields'] ?? null );
				$wants       = static function ( string $field ) use ( $fields ): bool {
					return empty( $fields ) || in_array( $field, $fields, true );
				};
				$needs_meta  = $wants( 'width' ) || $wants( 'height' );
				$result      = array();
				foreach ( $attachments as $att ) {
					$meta = $needs_meta ? wp_get_attachment_metadata( $att->ID ) : array();
					$row  = array(
						'ID'        => $att->ID,
						'title'     => $att->post_title,
						'caption'   => $att->post_excerpt,
						'alt'       => $wants( 'alt' ) ? get_post_meta( $att->ID, '_wp_attachment_image_alt', true ) : null,
						'url'       => $wants( 'url' ) ? wp_get_attachment_url( $att->ID ) : null,
						'mime_type' => $att->post_mime_type,
						'date'      => $att->post_date,
						'width'     => is_array( $meta ) ? ( $meta['width'] ?? null ) : null,
						'height'    => is_array( $meta ) ? ( $meta['height'] ?? null ) : null,
						'edit_link' => $wants( 'edit_link' ) ? get_edit_post_link( $att->ID, 'raw' ) : null,
					);
					$result[] = mswpa_apply_fields( $row, $fields, array( 'ID' ) );
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/update-media-meta',
		array(
			'label'               => __( 'Update Media Meta', 'ms-wp-abilities' ),
			'description'         => __( 'Update alt text, title, caption, or description for a media item. Direct update — no preview step needed for metadata changes.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'ID' ),
				'properties' => array(
					'ID'          => array( 'type' => 'integer' ),
					'alt'         => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'upload_files' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['ID'] ) ) {
					return new WP_Error( 'missing_id', __( 'An attachment ID is required.', 'ms-wp-abilities' ) );
				}
				$att_id  = absint( $input['ID'] );
				$updated = array();
				$args    = array( 'ID' => $att_id );

				if ( isset( $input['title'] ) ) {
					$args['post_title'] = sanitize_text_field( $input['title'] );
					$updated[] = 'title';
				}
				if ( isset( $input['caption'] ) ) {
					$args['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
					$updated[] = 'caption';
				}
				if ( isset( $input['description'] ) ) {
					$args['post_content'] = sanitize_textarea_field( $input['description'] );
					$updated[] = 'description';
				}
				if ( count( $args ) > 1 ) {
					$result = wp_update_post( $args, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( isset( $input['alt'] ) ) {
					update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
					$updated[] = 'alt';
				}
				return array(
					'ID'      => $att_id,
					'updated' => $updated,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// USERS
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-users',
		array(
			'label'               => __( 'Get Users', 'ms-wp-abilities' ),
			'description'         => __( 'List users with optional role filtering.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'role'     => array(
						'type'        => 'string',
						'description' => 'e.g. administrator, editor, author, subscriber',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'list_users' ),
			'execute_callback'    => function ( $input ) {
				$args = array(
					'number'  => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20,
					'orderby' => 'display_name',
					'order'   => 'ASC',
				);
				if ( ! empty( $input['role'] ) ) {
					$args['role'] = sanitize_key( $input['role'] );
				}
				$users  = get_users( $args );
				$result = array();
				foreach ( $users as $user ) {
					$result[] = array(
						'ID'           => $user->ID,
						'display_name' => $user->display_name,
						'user_email'   => $user->user_email,
						'user_login'   => $user->user_login,
						'roles'        => $user->roles,
						'registered'   => $user->user_registered,
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// SITE
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-site-settings',
		array(
			'label'               => __( 'Get Site Settings', 'ms-wp-abilities' ),
			'description'         => __( 'Read key WordPress site settings: name, description, URL, timezone, date format, posts per page, language, active theme.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				return array(
					'blogname'            => get_option( 'blogname' ),
					'blogdescription'     => get_option( 'blogdescription' ),
					'siteurl'             => get_option( 'siteurl' ),
					'home'                => get_option( 'home' ),
					'admin_email'         => get_option( 'admin_email' ),
					'timezone_string'     => get_option( 'timezone_string' ),
					'date_format'         => get_option( 'date_format' ),
					'time_format'         => get_option( 'time_format' ),
					'posts_per_page'      => (int) get_option( 'posts_per_page' ),
					'language'            => get_option( 'WPLANG' ) ? get_option( 'WPLANG' ) : 'en_US',
					'active_theme'        => get_option( 'stylesheet' ),
					'active_theme_name'   => wp_get_theme()->get( 'Name' ),
					'wp_version'          => get_bloginfo( 'version' ),
					'permalink_structure' => get_option( 'permalink_structure' ),
					'comments_open'       => 'open' === get_option( 'default_comment_status' ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/get-menus',
		array(
			'label'               => __( 'Get Menus', 'ms-wp-abilities' ),
			'description'         => __( 'List all navigation menus with their items. Pass include_items: false to skip item details.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'include_items' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
			'execute_callback'    => function ( $input ) {
				$menus  = wp_get_nav_menus();
				$result = array();
				foreach ( $menus as $menu ) {
					$entry = array(
						'term_id' => $menu->term_id,
						'name'    => $menu->name,
						'slug'    => $menu->slug,
						'count'   => $menu->count,
					);
					if ( rest_sanitize_boolean( $input['include_items'] ?? true ) ) {
						$items = wp_get_nav_menu_items( $menu->term_id );
						$entry['items'] = array();
						if ( $items ) {
							foreach ( $items as $item ) {
								$entry['items'][] = array(
									'ID'         => $item->ID,
									'title'      => $item->title,
									'url'        => $item->url,
									'type'       => $item->type,
									'object'     => $item->object,
									'object_id'  => $item->object_id,
									'parent'     => $item->menu_item_parent,
									'menu_order' => $item->menu_order,
								);
							}
						}
					}
					$result[] = $entry;
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// PLUGINS
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-plugins',
		array(
			'label'               => __( 'Get Plugins', 'ms-wp-abilities' ),
			'description'         => __( 'List all installed plugins with name, version, active status, and whether an update is available.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'active', 'inactive' ),
						'default' => 'all',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'activate_plugins' ),
			'execute_callback'    => function ( $input ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins        = get_plugins();
				$active_plugins = get_option( 'active_plugins', array() );
				$update_plugins = get_site_transient( 'update_plugins' );
				$status_filter  = isset( $input['status'] ) ? $input['status'] : 'all';
				$result         = array();

				foreach ( $plugins as $plugin_file => $plugin_data ) {
					$is_active = in_array( $plugin_file, $active_plugins, true );
					if ( 'active' === $status_filter && ! $is_active ) {
						continue;
					}
					if ( 'inactive' === $status_filter && $is_active ) {
						continue;
					}
					$update_available = isset( $update_plugins->response[ $plugin_file ] );
					$new_version      = $update_available ? $update_plugins->response[ $plugin_file ]->new_version : null;
					$result[] = array(
						'plugin_file'      => $plugin_file,
						'name'             => $plugin_data['Name'],
						'version'          => $plugin_data['Version'],
						'description'      => $plugin_data['Description'],
						'author'           => $plugin_data['Author'],
						'active'           => $is_active,
						'update_available' => $update_available,
						'new_version'      => $new_version,
						'plugin_url'       => $plugin_data['PluginURI'],
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/install-plugin',
		array(
			'label'               => __( 'Install Plugin', 'ms-wp-abilities' ),
			'description'         => __( 'Install a plugin from the WordPress.org plugin directory by its slug. Does not activate after install unless activate: true is passed.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug' ),
				'properties' => array(
					'slug'     => array(
						'type'        => 'string',
						'description' => 'WordPress.org plugin slug, e.g. woocommerce.',
					),
					'activate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'install_plugins' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['slug'] ) ) {
					return new WP_Error( 'missing_slug', __( 'A plugin slug is required.', 'ms-wp-abilities' ) );
				}
				$slug = sanitize_key( $input['slug'] );

				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

				$api = plugins_api(
					'plugin_information',
					array(
						'slug'   => $slug,
						'fields' => array(
							'sections' => false,
							'reviews'  => false,
						),
					)
				);
				if ( is_wp_error( $api ) || ! is_object( $api ) ) {
					return new WP_Error(
						'plugin_not_found',
						sprintf(
							/* translators: %s: plugin slug. */
							__( 'Plugin "%s" not found on WordPress.org.', 'ms-wp-abilities' ),
							$slug
						)
					);
				}

				$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
				$result   = $upgrader->install( $api->download_link );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'install_failed', __( 'Plugin installation failed.', 'ms-wp-abilities' ) );
				}

				$plugin_file = $upgrader->plugin_info();
				$activated   = false;
				if ( rest_sanitize_boolean( $input['activate'] ?? false ) && $plugin_file ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					$activate_result = activate_plugin( $plugin_file );
					$activated       = ! is_wp_error( $activate_result );
				}

				return array(
					'slug'        => $slug,
					'name'        => $api->name,
					'version'     => $api->version,
					'plugin_file' => $plugin_file,
					'installed'   => true,
					'activated'   => $activated,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/activate-plugin',
		array(
			'label'               => __( 'Activate Plugin', 'ms-wp-abilities' ),
			'description'         => __( 'Activate an installed plugin by its plugin file path (e.g. woocommerce/woocommerce.php). Get plugin file paths from get-plugins.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'plugin_file' ),
				'properties' => array(
					'plugin_file' => array(
						'type'        => 'string',
						'description' => 'Plugin file path, e.g. woocommerce/woocommerce.php.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'activate_plugins' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['plugin_file'] ) ) {
					return new WP_Error( 'missing_plugin_file', __( 'A plugin file path is required.', 'ms-wp-abilities' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$plugin_file = sanitize_text_field( $input['plugin_file'] );
				if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
					return new WP_Error( 'plugin_not_installed', __( 'Plugin is not installed.', 'ms-wp-abilities' ) );
				}
				$result = activate_plugin( $plugin_file );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'plugin_file' => $plugin_file,
					'activated'   => true,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/update-plugin',
		array(
			'label'               => __( 'Update Plugin', 'ms-wp-abilities' ),
			'description'         => __( 'Update a plugin to its latest version. Use get-available-updates to find plugins with updates, then get-plugins for the plugin_file path.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'plugin_file' ),
				'properties' => array(
					'plugin_file' => array(
						'type'        => 'string',
						'description' => 'Plugin file path, e.g. woocommerce/woocommerce.php.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'update_plugins' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['plugin_file'] ) ) {
					return new WP_Error( 'missing_plugin_file', __( 'A plugin file path is required.', 'ms-wp-abilities' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
				require_once ABSPATH . 'wp-admin/includes/plugin.php';

				$plugin_file    = sanitize_text_field( $input['plugin_file'] );
				$update_plugins = get_site_transient( 'update_plugins' );

				if ( ! isset( $update_plugins->response[ $plugin_file ] ) ) {
					return new WP_Error( 'no_update', __( 'No update available for this plugin.', 'ms-wp-abilities' ) );
				}

				$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
				$result   = $upgrader->upgrade( $plugin_file );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$plugins = get_plugins();
				return array(
					'plugin_file' => $plugin_file,
					'updated'     => true,
					'new_version' => $plugins[ $plugin_file ]['Version'] ?? null,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// THEMES
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-themes',
		array(
			'label'               => __( 'Get Themes', 'ms-wp-abilities' ),
			'description'         => __( 'List all installed themes with version, author, active status, and whether an update is available.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'permission_callback' => fn() => current_user_can( 'switch_themes' ),
			'execute_callback'    => function () {
				$themes        = wp_get_themes();
				$active_theme  = get_option( 'stylesheet' );
				$update_themes = get_site_transient( 'update_themes' );
				$result        = array();
				foreach ( $themes as $slug => $theme ) {
					$update_available = isset( $update_themes->response[ $slug ] );
					$result[] = array(
						'slug'             => $slug,
						'name'             => $theme->get( 'Name' ),
						'version'          => $theme->get( 'Version' ),
						'author'           => $theme->get( 'Author' ),
						'description'      => $theme->get( 'Description' ),
						'active'           => $slug === $active_theme,
						'update_available' => $update_available,
						'new_version'      => $update_available ? $update_themes->response[ $slug ]['new_version'] : null,
					);
				}
				return $result;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/update-theme',
		array(
			'label'               => __( 'Update Theme', 'ms-wp-abilities' ),
			'description'         => __( 'Update a theme to its latest version. Use get-available-updates to find themes with updates, then get-themes for the slug.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug' ),
				'properties' => array(
					'slug' => array(
						'type'        => 'string',
						'description' => 'Theme directory slug.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'update_themes' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['slug'] ) ) {
					return new WP_Error( 'missing_slug', __( 'A theme slug is required.', 'ms-wp-abilities' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

				$slug          = sanitize_key( $input['slug'] );
				$update_themes = get_site_transient( 'update_themes' );

				if ( ! isset( $update_themes->response[ $slug ] ) ) {
					return new WP_Error( 'no_update', __( 'No update available for this theme.', 'ms-wp-abilities' ) );
				}

				$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
				$result   = $upgrader->upgrade( $slug );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$theme = wp_get_theme( $slug );
				return array(
					'slug'        => $slug,
					'updated'     => true,
					'new_version' => $theme->get( 'Version' ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// UPDATES
	// =========================================================================

	wp_register_ability(
		'miriamschwab/get-available-updates',
		array(
			'label'               => __( 'Get Available Updates', 'ms-wp-abilities' ),
			'description'         => __( 'Check what plugin, theme, and WordPress core updates are available. Uses cached update data.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'update_plugins' ),
			'execute_callback'    => function () {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$plugins        = get_plugins();
				$update_plugins = get_site_transient( 'update_plugins' );
				$plugin_updates = array();
				if ( ! empty( $update_plugins->response ) ) {
					foreach ( $update_plugins->response as $plugin_file => $update_data ) {
						$plugin_updates[] = array(
							'plugin_file'     => $plugin_file,
							'name'            => $plugins[ $plugin_file ]['Name'] ?? $plugin_file,
							'current_version' => $plugins[ $plugin_file ]['Version'] ?? null,
							'new_version'     => $update_data->new_version,
						);
					}
				}

				$themes        = wp_get_themes();
				$update_themes = get_site_transient( 'update_themes' );
				$theme_updates = array();
				if ( ! empty( $update_themes->response ) ) {
					foreach ( $update_themes->response as $slug => $update_data ) {
						$theme = $themes[ $slug ] ?? null;
						$theme_updates[] = array(
							'slug'            => $slug,
							'name'            => $theme ? $theme->get( 'Name' ) : $slug,
							'current_version' => $theme ? $theme->get( 'Version' ) : null,
							'new_version'     => $update_data['new_version'],
						);
					}
				}

				$update_core = get_site_transient( 'update_core' );
				$core_update = null;
				if ( ! empty( $update_core->updates ) ) {
					foreach ( $update_core->updates as $update ) {
						if ( 'upgrade' === $update->response ) {
							$core_update = array(
								'current_version' => get_bloginfo( 'version' ),
								'new_version'     => $update->current,
							);
							break;
						}
					}
				}

				return array(
					'plugins'       => $plugin_updates,
					'themes'        => $theme_updates,
					'core'          => $core_update,
					'total_updates' => count( $plugin_updates ) + count( $theme_updates ) + ( $core_update ? 1 : 0 ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	// =========================================================================
	// REST BRIDGE
	// =========================================================================
	// Generic access to any registered REST route (core or third-party) for
	// cases not covered by a dedicated ability above. Read is unrestricted;
	// write is confirmed conversationally by the agent before every call and
	// hard-blocked here for a short list of especially destructive routes
	// regardless of what was agreed in conversation.

	wp_register_ability(
		'miriamschwab/rest-get',
		array(
			'label'               => __( 'REST Get', 'ms-wp-abilities' ),
			'description'         => __( 'Call any registered WordPress REST API route with GET only — core or a third-party plugin\'s namespace (e.g. comments, WooCommerce, Gravity Forms). Use this before concluding that a feature or piece of data isn\'t available, or asking the user to clarify — most plugins register their own REST namespace matching their slug (e.g. angie/angie.php → /angie/v1, wordpress-seo/wp-seo.php → /yoast/v1). Query that namespace root directly (e.g. /angie/v1) rather than the global index (/) — the full site index can be hundreds of KB and exceed output limits; only fall back to / and filter its routes by keyword if the namespace can\'t be guessed. This ability cannot issue any write method.', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'route' ),
				'properties' => array(
					'route'  => array(
						'type'        => 'string',
						'description' => 'REST route, e.g. /wp/v2/comments or /wc/v3/orders.',
					),
					'params' => array(
						'type'        => 'object',
						'description' => 'Query parameters for the request.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['route'] ) ) {
					return new WP_Error( 'missing_route', __( 'A REST route is required.', 'ms-wp-abilities' ) );
				}
				$route   = '/' . ltrim( sanitize_text_field( $input['route'] ), '/' );
				$request = new WP_REST_Request( 'GET', $route );
				if ( ! empty( $input['params'] ) && is_array( $input['params'] ) ) {
					foreach ( $input['params'] as $key => $value ) {
						$request->set_param( sanitize_key( $key ), $value );
					}
				}
				$response = rest_do_request( $request );
				$status   = $response->get_status();
				$data     = $response->get_data();
				if ( $status >= 400 ) {
					$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : __( 'REST request failed.', 'ms-wp-abilities' );
					return new WP_Error( 'rest_get_failed', $message, array( 'status' => $status ) );
				}
				return array(
					'status' => $status,
					'data'   => $data,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);

	wp_register_ability(
		'miriamschwab/rest-write',
		array(
			'label'               => __( 'REST Write', 'ms-wp-abilities' ),
			'description'         => __( 'Call any registered WordPress REST API route with a write method (POST, PUT, PATCH, DELETE) — core or a third-party plugin\'s namespace. Use this before concluding that a write action isn\'t possible — most plugins register their own REST namespace matching their slug (e.g. angie/angie.php → /angie/v1); check that namespace\'s routes with rest-get rather than assuming no endpoint exists. CORRECT USAGE: state the exact route, method, and payload to the user in plain language ("Here\'s what I propose... should I go ahead?") and wait for explicit confirmation before calling. Hard-blocked regardless of confirmation: any write to /wp/v2/users, DELETE on /wp/v2/plugins, any write to /wp/v2/settings, and any force=true (permanent delete bypassing trash).', 'ms-wp-abilities' ),
			'category'            => 'miriamschwab',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'route', 'method' ),
				'properties' => array(
					'route'  => array(
						'type'        => 'string',
						'description' => 'REST route, e.g. /wp/v2/comments/45.',
					),
					'method' => array(
						'type' => 'string',
						'enum' => array( 'POST', 'PUT', 'PATCH', 'DELETE' ),
					),
					'body'   => array(
						'type'        => 'object',
						'description' => 'Request body / params for the write.',
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'execute_callback'    => function ( $input ) {
				if ( empty( $input['route'] ) || empty( $input['method'] ) ) {
					return new WP_Error( 'missing_fields', __( 'A route and method are required.', 'ms-wp-abilities' ) );
				}
				$method = strtoupper( sanitize_text_field( $input['method'] ) );
				if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
					return new WP_Error( 'invalid_method', __( 'Method must be POST, PUT, PATCH, or DELETE.', 'ms-wp-abilities' ) );
				}
				$route = '/' . ltrim( sanitize_text_field( $input['route'] ), '/' );
				// Normalize body keys BEFORE the block check, so the guard inspects the
				// exact keys that will be dispatched below. Checking raw keys here and
				// normalizing at dispatch would let {"Force": true} pass the guard and
				// still arrive at the endpoint as "force".
				$body = ! empty( $input['body'] ) && is_array( $input['body'] ) ? mswpa_normalize_rest_body( $input['body'] ) : array();

				$blocked = mswpa_rest_write_blocked_reason( $route, $method, $body );
				if ( $blocked ) {
					return new WP_Error( 'rest_write_blocked', $blocked, array( 'status' => 403 ) );
				}

				$request = new WP_REST_Request( $method, $route );
				foreach ( $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$response = rest_do_request( $request );
				$status   = $response->get_status();
				$data     = $response->get_data();
				if ( $status >= 400 ) {
					$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : __( 'REST request failed.', 'ms-wp-abilities' );
					return new WP_Error( 'rest_write_failed', $message, array( 'status' => $status ) );
				}
				return array(
					'status' => $status,
					'data'   => $data,
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

// -------------------------------------------------------------------------
// Markdown to Gutenberg blocks converter.
// -------------------------------------------------------------------------

/**
 * Convert a Markdown string to serialized Gutenberg block markup.
 * Strips YAML frontmatter and a leading H1 (post title is set separately).
 *
 * @param string $markdown Markdown source.
 * @return string Serialized Gutenberg block markup.
 */
function mswpa_markdown_to_blocks( string $markdown ): string {
	// Strip YAML frontmatter.
	$markdown = preg_replace( '/^---\s*\n.*?\n---\s*\n/s', '', $markdown );

	// Strip leading H1.
	$markdown = preg_replace( '/^#\s+[^\n]+\n?/', '', ltrim( $markdown ) );

	$lines  = explode( "\n", str_replace( "\r\n", "\n", $markdown ) );
	$blocks = array();
	$i      = 0;
	$total  = count( $lines );

	while ( $i < $total ) {
		$line = $lines[ $i ];

		// Fenced code block.
		if ( preg_match( '/^```/', $line ) ) {
			$code_lines = array();
			++$i;
			while ( $i < $total && ! preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
				$code_lines[] = $lines[ $i ];
				++$i;
			}
			$code     = htmlspecialchars( implode( "\n", $code_lines ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$blocks[] = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . $code . "</code></pre>\n<!-- /wp:code -->";
			++$i;
			continue;
		}

		// Horizontal rule.
		if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})\s*$/', $line ) ) {
			$blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
			++$i;
			continue;
		}

		// Heading.
		if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
			$level    = min( 6, strlen( $m[1] ) );
			$text     = mswpa_inline_md( trim( $m[2] ) );
			$blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>\n<!-- /wp:heading -->";
			++$i;
			continue;
		}

		// Unordered list group.
		if ( preg_match( '/^[-*+]\s+/', $line ) ) {
			$items = array();
			while ( $i < $total && preg_match( '/^[-*+]\s+(.+)$/', $lines[ $i ], $m ) ) {
				$items[] = '<li>' . mswpa_inline_md( $m[1] ) . '</li>';
				++$i;
			}
			$blocks[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( '', $items ) . "</ul>\n<!-- /wp:list -->";
			continue;
		}

		// Ordered list group.
		if ( preg_match( '/^\d+\.\s+/', $line ) ) {
			$items = array();
			while ( $i < $total && preg_match( '/^\d+\.\s+(.+)$/', $lines[ $i ], $m ) ) {
				$items[] = '<li>' . mswpa_inline_md( $m[1] ) . '</li>';
				++$i;
			}
			$blocks[] = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . implode( '', $items ) . "</ol>\n<!-- /wp:list -->";
			continue;
		}

		// Empty line.
		if ( trim( $line ) === '' ) {
			++$i;
			continue;
		}

		// Paragraph: collect consecutive lines until a blank line or block-level element.
		$para_lines = array();
		while ( $i < $total ) {
			$l = $lines[ $i ];
			if ( trim( $l ) === '' ) {
				break;
			}
			if ( preg_match( '/^#{1,6}\s/', $l ) ) {
				break;
			}
			if ( preg_match( '/^[-*+]\s/', $l ) ) {
				break;
			}
			if ( preg_match( '/^\d+\.\s/', $l ) ) {
				break;
			}
			if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})\s*$/', $l ) ) {
				break;
			}
			if ( preg_match( '/^```/', $l ) ) {
				break;
			}
			$para_lines[] = $l;
			++$i;
		}
		if ( $para_lines ) {
			$text     = mswpa_inline_md( implode( ' ', $para_lines ) );
			$blocks[] = "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
		}
	}

	return implode( "\n\n", $blocks );
}

/**
 * Apply inline Markdown formatting (bold, italic, links, inline code).
 *
 * @param string $text Inline Markdown text.
 * @return string HTML with inline formatting applied.
 */
function mswpa_inline_md( string $text ): string {
	// Protect inline code from other substitutions.
	$placeholders = array();
	$text         = preg_replace_callback(
		'/`([^`]+)`/',
		function ( $m ) use ( &$placeholders ) {
			$key                  = "\x00" . count( $placeholders ) . "\x00";
			$placeholders[ $key ] = '<code>' . htmlspecialchars( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</code>';
			return $key;
		},
		$text
	);

	// Links — label may contain **bold** or *italic*.
	$text = preg_replace_callback(
		'/\[([^\]]*)\]\(([^)]+)\)/',
		function ( $m ) {
			$label = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $m[1] );
			$label = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $label );
			return '<a href="' . esc_url( $m[2] ) . '">' . $label . '</a>';
		},
		$text
	);

	// Bold: **text**.
	$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );

	// Italic: *text* (not adjacent to another *).
	$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text );

	// Restore inline code.
	foreach ( $placeholders as $key => $val ) {
		$text = str_replace( $key, $val, $text );
	}

	return $text;
}

// -------------------------------------------------------------------------
// Admin UI: browse and filter registered abilities.
// -------------------------------------------------------------------------

add_action( 'admin_menu', 'mswpa_add_admin_menu' );
/**
 * Register the "WP Abilities" admin page under Tools.
 *
 * @return void
 */
function mswpa_add_admin_menu() {
	add_management_page(
		__( 'WP Abilities', 'ms-wp-abilities' ),
		__( 'WP Abilities', 'ms-wp-abilities' ),
		'manage_options',
		'ms-wp-abilities',
		'mswpa_render_admin_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mswpa_plugin_action_links' );
/**
 * Add a "View Abilities" link to the plugin's row on the Plugins list page.
 *
 * @param array $links Existing action links.
 * @return array Filtered action links.
 */
function mswpa_plugin_action_links( $links ) {
	$view_link = '<a href="' . esc_url( admin_url( 'tools.php?page=ms-wp-abilities' ) ) . '">' . esc_html__( 'View Abilities', 'ms-wp-abilities' ) . '</a>';
	array_unshift( $links, $view_link );
	return $links;
}

/**
 * Get the namespace portion of an ability name (the part before the slash).
 *
 * @param string $name Full ability name, e.g. "miriamschwab/get-posts".
 * @return string Namespace, e.g. "miriamschwab".
 */
function mswpa_ability_namespace( string $name ): string {
	$slash = strpos( $name, '/' );
	return false !== $slash ? substr( $name, 0, $slash ) : $name;
}

/**
 * Query registered abilities, optionally filtered by category, namespace, and search term.
 *
 * Uses the wp_get_abilities() $args filtering added in WordPress 7.1 when the site is
 * running 7.1+; falls back to manual array_filter() on older versions (this plugin's
 * floor is 6.9). The bundled PHPStan WordPress stubs still describe the pre-7.1
 * zero-argument signature, hence the ignore below.
 *
 * @param string $category         Category slug to filter by, or '' for all.
 * @param string $namespace_filter Ability namespace to filter by, or '' for all.
 * @param string $search           Free-text search across name, label, and description, or '' for none.
 * @return WP_Ability[] Matching abilities, keyed by name.
 */
function mswpa_query_abilities( string $category, string $namespace_filter, string $search ): array {
	if ( version_compare( get_bloginfo( 'version' ), '7.1', '>=' ) ) {
		$args = array();
		if ( $category ) {
			$args['category'] = $category;
		}
		if ( $namespace_filter ) {
			$args['namespace'] = $namespace_filter;
		}
		$abilities = wp_get_abilities( $args ); // @phpstan-ignore-line -- WP 7.1 args, stubs predate it.
	} else {
		$abilities = wp_get_abilities();
		if ( $category ) {
			$abilities = array_filter( $abilities, static fn( $ability ) => $ability->get_category() === $category );
		}
		if ( $namespace_filter ) {
			$abilities = array_filter( $abilities, static fn( $ability ) => mswpa_ability_namespace( $ability->get_name() ) === $namespace_filter );
		}
	}

	if ( $search ) {
		$abilities = array_filter(
			$abilities,
			static function ( $ability ) use ( $search ) {
				$haystack = $ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description();
				return false !== stripos( $haystack, $search );
			}
		);
	}

	ksort( $abilities );

	return $abilities;
}

/**
 * Compare the current ability set against the snapshot saved on the last page view,
 * report what's new/removed since then, and save the current set as the new snapshot.
 *
 * First-ever visit (no stored snapshot) reports no diff — it just establishes the
 * baseline, so a fresh install doesn't report every existing ability as "new."
 *
 * @param WP_Ability[] $all_abilities Every currently registered ability, keyed by name.
 * @return array{is_first_visit: bool, new: array<string, string>, removed: array<string, string>}
 *     `new`/`removed` are ability name => label, keyed by name.
 */
function mswpa_diff_abilities_snapshot( array $all_abilities ): array {
	$current = array();
	foreach ( $all_abilities as $ability ) {
		$current[ $ability->get_name() ] = $ability->get_label();
	}

	$stored         = get_option( MSWPA_ABILITIES_SNAPSHOT_OPTION, false );
	$is_first_visit = ( false === $stored );

	$new     = array();
	$removed = array();
	if ( ! $is_first_visit ) {
		foreach ( array_diff_key( $current, $stored ) as $name => $label ) {
			$new[ $name ] = $label;
		}
		foreach ( array_diff_key( $stored, $current ) as $name => $label ) {
			$removed[ $name ] = $label;
		}
		ksort( $new );
		ksort( $removed );
	}

	update_option( MSWPA_ABILITIES_SNAPSHOT_OPTION, $current, false );

	return array(
		'is_first_visit' => $is_first_visit,
		'new'            => $new,
		'removed'        => $removed,
	);
}

/**
 * Render the "WP Abilities" admin page.
 *
 * @return void
 */
function mswpa_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ms-wp-abilities' ) );
	}

	// Read-only filter form (GET, no state change) — no nonce required.
	$category  = isset( $_GET['mswpa_category'] ) ? sanitize_key( wp_unslash( $_GET['mswpa_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter form, no state change.
	$namespace = isset( $_GET['mswpa_namespace'] ) ? sanitize_key( wp_unslash( $_GET['mswpa_namespace'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter form, no state change.
	$search    = isset( $_GET['mswpa_search'] ) ? sanitize_text_field( wp_unslash( $_GET['mswpa_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter form, no state change.

	$all_abilities = mswpa_query_abilities( '', '', '' );
	$snapshot_diff = mswpa_diff_abilities_snapshot( $all_abilities );

	$categories = array();
	$namespaces = array();
	foreach ( $all_abilities as $ability ) {
		$categories[ $ability->get_category() ]                        = true;
		$namespaces[ mswpa_ability_namespace( $ability->get_name() ) ] = true;
	}
	ksort( $categories );
	ksort( $namespaces );

	$registered_categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : array();
	$filtered              = mswpa_query_abilities( $category, $namespace, $search );
	$reset_url             = admin_url( 'tools.php?page=ms-wp-abilities' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WP Abilities', 'ms-wp-abilities' ); ?></h1>

		<?php if ( $snapshot_diff['is_first_visit'] ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: %d: number of abilities recorded as the baseline. */
						esc_html__( 'Baseline recorded: %d abilities. New or removed abilities will be flagged here on your next visit.', 'ms-wp-abilities' ),
						count( $all_abilities )
					);
					?>
				</p>
			</div>
		<?php elseif ( $snapshot_diff['new'] || $snapshot_diff['removed'] ) : ?>
			<div class="notice notice-info">
				<?php if ( $snapshot_diff['new'] ) : ?>
					<p>
						<strong>
							<?php
							printf(
								/* translators: %d: number of new abilities. */
								esc_html( _n( 'New since your last visit (%d):', 'New since your last visit (%d):', count( $snapshot_diff['new'] ), 'ms-wp-abilities' ) ),
								count( $snapshot_diff['new'] )
							);
							?>
						</strong>
					</p>
					<ul class="mswpa-diff-list">
						<?php foreach ( $snapshot_diff['new'] as $name => $label ) : ?>
							<li><code><?php echo esc_html( $name ); ?></code> — <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $snapshot_diff['removed'] ) : ?>
					<p>
						<strong>
							<?php
							printf(
								/* translators: %d: number of removed abilities. */
								esc_html( _n( 'No longer registered since your last visit (%d):', 'No longer registered since your last visit (%d):', count( $snapshot_diff['removed'] ), 'ms-wp-abilities' ) ),
								count( $snapshot_diff['removed'] )
							);
							?>
						</strong>
					</p>
					<ul class="mswpa-diff-list">
						<?php foreach ( $snapshot_diff['removed'] as $name => $label ) : ?>
							<li><code><?php echo esc_html( $name ); ?></code> — <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: %d: number of registered abilities. */
				esc_html( _n( '%d ability currently registered on this site.', '%d abilities currently registered on this site.', count( $all_abilities ), 'ms-wp-abilities' ) ),
				count( $all_abilities )
			);
			?>
		</p>

		<form method="get">
			<input type="hidden" name="page" value="ms-wp-abilities">
			<p class="mswpa-filters">
				<label for="mswpa-category-filter"><?php esc_html_e( 'Category', 'ms-wp-abilities' ); ?></label>
				<select name="mswpa_category" id="mswpa-category-filter">
					<option value=""><?php esc_html_e( 'All categories', 'ms-wp-abilities' ); ?></option>
					<?php foreach ( array_keys( $categories ) as $cat_slug ) : ?>
						<?php $cat_label = isset( $registered_categories[ $cat_slug ] ) ? $registered_categories[ $cat_slug ]->get_label() : $cat_slug; ?>
						<option value="<?php echo esc_attr( $cat_slug ); ?>" <?php selected( $category, $cat_slug ); ?>>
							<?php echo esc_html( $cat_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="mswpa-namespace-filter"><?php esc_html_e( 'Namespace', 'ms-wp-abilities' ); ?></label>
				<select name="mswpa_namespace" id="mswpa-namespace-filter">
					<option value=""><?php esc_html_e( 'All namespaces', 'ms-wp-abilities' ); ?></option>
					<?php foreach ( array_keys( $namespaces ) as $ns ) : ?>
						<option value="<?php echo esc_attr( $ns ); ?>" <?php selected( $namespace, $ns ); ?>>
							<?php echo esc_html( $ns ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="mswpa-search-filter"><?php esc_html_e( 'Search', 'ms-wp-abilities' ); ?></label>
				<input type="search" name="mswpa_search" id="mswpa-search-filter" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Name, label, or description', 'ms-wp-abilities' ); ?>">

				<?php submit_button( __( 'Filter', 'ms-wp-abilities' ), 'secondary', '', false ); ?>
				<?php if ( $category || $namespace || $search ) : ?>
					<a class="button" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Reset', 'ms-wp-abilities' ); ?></a>
				<?php endif; ?>
			</p>
		</form>

		<p>
			<?php
			printf(
				/* translators: 1: number of abilities shown after filtering, 2: total number of registered abilities. */
				esc_html__( 'Showing %1$d of %2$d abilities.', 'ms-wp-abilities' ),
				count( $filtered ),
				count( $all_abilities )
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'Label', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'Category', 'ms-wp-abilities' ); ?></th>
					<th style="white-space: nowrap;"><?php esc_html_e( 'MCP Public', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'Description', 'ms-wp-abilities' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $filtered ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No abilities match the current filters.', 'ms-wp-abilities' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $filtered as $ability ) : ?>
						<?php
						$meta      = $ability->get_meta();
						$is_public = ! empty( $meta['mcp']['public'] );
						$schema    = $ability->get_input_schema();
						$cat_slug  = $ability->get_category();
						$cat_label = isset( $registered_categories[ $cat_slug ] ) ? $registered_categories[ $cat_slug ]->get_label() : $cat_slug;
						?>
						<tr>
							<td><code><?php echo esc_html( $ability->get_name() ); ?></code></td>
							<td><?php echo esc_html( $ability->get_label() ); ?></td>
							<td><?php echo esc_html( $cat_label ); ?></td>
							<td><?php echo $is_public ? esc_html__( 'Yes', 'ms-wp-abilities' ) : esc_html__( 'No', 'ms-wp-abilities' ); ?></td>
							<td>
								<?php echo esc_html( $ability->get_description() ); ?>
								<?php if ( ! empty( $schema ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'Input schema', 'ms-wp-abilities' ); ?></summary>
										<pre class="mswpa-schema"><?php echo esc_html( (string) wp_json_encode( $schema, JSON_PRETTY_PRINT ) ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Recent write activity', 'ms-wp-abilities' ); ?></h2>
		<?php
		$audit_log   = get_option( MSWPA_AUDIT_LOG_OPTION, array() );
		$audit_log   = is_array( $audit_log ) ? $audit_log : array();
		$time_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %d: maximum number of log entries retained. */
				esc_html__( 'The last %d invocations of an ability that changes content or configuration, newest first. Recorded before the permission check, so calls that were blocked or refused appear here too. Input values are recorded only for identifying fields — post content, REST bodies and meta values are never stored.', 'ms-wp-abilities' ),
				(int) MSWPA_AUDIT_LOG_MAX
			);
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'User', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'ms-wp-abilities' ); ?></th>
					<th><?php esc_html_e( 'Input', 'ms-wp-abilities' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $audit_log ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No write abilities have been invoked yet.', 'ms-wp-abilities' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $audit_log as $entry ) : ?>
						<?php
						$entry_user = isset( $entry['user'] ) ? (int) $entry['user'] : 0;
						$user_data  = $entry_user ? get_userdata( $entry_user ) : false;
						$user_label = $user_data ? $user_data->user_login : __( 'none', 'ms-wp-abilities' );
						$entry_keys = isset( $entry['keys'] ) && is_array( $entry['keys'] ) ? $entry['keys'] : array();
						$entry_vals = isset( $entry['values'] ) && is_array( $entry['values'] ) ? $entry['values'] : array();
						$pairs      = array();
						foreach ( $entry_vals as $vk => $vv ) {
							$pairs[] = $vk . '=' . $vv;
						}
						$other_keys = array_diff( $entry_keys, array_keys( $entry_vals ) );
						?>
						<tr>
							<td style="white-space: nowrap;"><?php echo esc_html( wp_date( $time_format, isset( $entry['time'] ) ? (int) $entry['time'] : 0 ) ); ?></td>
							<td><?php echo esc_html( $user_label ); ?></td>
							<td><code><?php echo esc_html( isset( $entry['ability'] ) ? (string) $entry['ability'] : '' ); ?></code></td>
							<td>
								<?php if ( $pairs ) : ?>
									<code><?php echo esc_html( implode( ', ', $pairs ) ); ?></code>
								<?php endif; ?>
								<?php if ( $other_keys ) : ?>
									<span class="description">
										<?php
										printf(
											/* translators: %s: comma-separated list of input field names. */
											esc_html__( 'also sent: %s', 'ms-wp-abilities' ),
											esc_html( implode( ', ', $other_keys ) )
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( ! $pairs && ! $other_keys ) : ?>
									<span class="description"><?php esc_html_e( 'no input', 'ms-wp-abilities' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<style>
		.mswpa-filters label { margin-left: 12px; font-weight: 600; }
		.mswpa-filters label:first-child { margin-left: 0; }
		.mswpa-schema { max-width: 480px; max-height: 200px; overflow: auto; background: #f6f7f7; padding: 8px; font-size: 11px; }
		.mswpa-diff-list { margin: 0 0 1em 1.5em; list-style: disc; }
	</style>
	<?php
}

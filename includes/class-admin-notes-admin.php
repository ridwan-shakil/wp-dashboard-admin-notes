<?php
/**
 * Admin page renderer and helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Notes_Admin {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_admin_notes_update_visibility', array( $this, 'ajax_admin_notes_update_visibility' ) );
		add_action( 'wp_ajax_admin_notes_update_roles', array( $this, 'ajax_admin_notes_update_roles' ) );
	}

	/**
	 * Add Admin Notes top-level menu.
	 */
	public function add_menu_page() {
		$capability = apply_filters( 'admin_notes_capability', 'edit_posts' );

		add_menu_page(
			__( 'Admin Notes', 'admin-notes' ),
			__( 'Admin Notes', 'admin-notes' ),
			$capability,
			'admin-notes',
			array( $this, 'render_page' ),
			'dashicons-edit'
		);
	}

	/**
	 * Ensure assets loaded only on our page as fallback.
	 *
	 * @param string $hook
	 */
	public function maybe_enqueue_assets( $hook ) {
		if ( isset( $_GET['page'] ) && 'admin-notes' === $_GET['page'] ) {
			// No action here; assets are enqueued by the Assets class.
		}
	}

	/**
	 * Render admin page (notes board).
	 */
	public function render_page() {
		// Capability check
		if ( ! current_user_can( apply_filters( 'admin_notes_capability', 'edit_posts' ) ) ) {
			wp_die( __( 'You do not have permission to view this page.', 'admin-notes' ) );
		}

		// Get notes ordered by meta _admin_notes_order, pinned first
		$notes = $this->get_notes_for_display();

		?>
		<div class="wrap admin-notes-wrap">
			<h1><?php esc_html_e( 'Admin Notes', 'admin-notes' ); ?></h1>

			<p class="admin-notes-actions">
				<button id="admin-notes-add" class="button button-primary"><?php esc_html_e( '+ Add New Note', 'admin-notes' ); ?></button>
			</p>

			<div id="admin-notes-board" class="admin-notes-board" aria-live="polite">
				<?php
				if ( empty( $notes ) ) {
					echo '<p class="admin-notes-empty">' . esc_html__( 'No notes yet. Click "Add New Note" to create one.', 'admin-notes' ) . '</p>';
				} else {
					foreach ( $notes as $note ) {
						echo $this->render_note_card( $note );
					}
				}
				?>
			</div>
			<!-- placeholder for toasts -->
			<div id="admin-notes-toast" aria-hidden="true"></div>
		</div>
		<?php
	}

	/**
	 * Retrieve notes for display and filter them by visibility.
	 *
	 * @return WP_Post[]
	 */
	public function get_notes_for_display() {
		$args = array(
			'post_type'      => 'admin_note',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_admin_notes_order',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );
		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return array();
		}

		$filtered        = array();
		$current_user_id = get_current_user_id();

		foreach ( $posts as $post ) {
			if ( $this->current_user_can_view_note( $current_user_id, $post ) ) {
				$filtered[] = $post;
			}
		}

		return $filtered;
	}

	/**
	 * Determine whether a user can view a given note.
	 *
	 * @param int     $user_id
	 * @param WP_Post $post
	 * @return bool
	 */
	protected function current_user_can_view_note( $user_id, $post ) {
		$visibility = get_post_meta( $post->ID, '_admin_notes_visibility', true );

		// Default to only_me if not set
		if ( '' === $visibility ) {
			$visibility = 'only_me';
		}

		// Author always can see their own note
		if ( intval( $post->post_author ) === intval( $user_id ) ) {
			return true;
		}

		// Only me: non-authors cannot see
		if ( 'only_me' === $visibility ) {
			return false;
		}

		// All admins: check manage_options or role administrator
		if ( 'all_admins' === $visibility ) {
			// check capability
			if ( user_can( $user_id, 'manage_options' ) ) {
				return true;
			}
			// fallback: check role 'administrator'
			$user = get_userdata( $user_id );
			if ( $user && is_array( $user->roles ) && in_array( 'administrator', $user->roles, true ) ) {
				return true;
			}
			return false;
		}

		// Editors & above: check edit_others_posts capability
		if ( 'editors_and_above' === $visibility ) {
			if ( user_can( $user_id, 'edit_others_posts' ) ) {
				return true;
			}
			return false;
		}

		// Default deny
		return false;
	}

	/**
	 * Render single note card markup (server-side helper).
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	public function render_note_card( $post ) {
		$post_id = intval( $post->ID );
		$title   = get_the_title( $post_id );
		$meta    = get_post_meta( $post_id );
		$color   = isset( $meta['_admin_notes_color'][0] ) ? esc_attr( $meta['_admin_notes_color'][0] ) : '#fff9c4';
		$check   = isset( $meta['_admin_notes_checklist'][0] ) ? wp_unslash( $meta['_admin_notes_checklist'][0] ) : '[]';
		$check   = json_decode( $check );
		if ( ! is_array( $check ) ) {
			$check = array();
		}

		// Get visibility meta
		$visibility = get_post_meta( $post_id, '_admin_notes_visibility', true );
		if ( '' === $visibility ) {
			$visibility = 'only_me';
		}

		// collapsed state is per-user (user meta)
		$user_min  = get_user_meta( get_current_user_id(), 'admin_notes_minimized', true );
		$collapsed = ( is_array( $user_min ) && in_array( $post_id, $user_min, true ) ) ? true : false;

		ob_start();
		?>
		<div class="admin-note-card" data-note-id="<?php echo esc_attr( $post_id ); ?>" style="background:<?php echo esc_attr( $color ); ?>;">
			<header class="admin-note-header" role="heading" aria-level="3">
				<span class="admin-note-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'admin-notes' ); ?>">☰</span>
				<input class="admin-note-title" value="<?php echo esc_attr( $title ); ?>" aria-label="<?php esc_attr_e( 'Note title', 'admin-notes' ); ?>" />
				<div class="admin-note-actions">
					<button class="admin-note-minimize" title="<?php esc_attr_e( 'Minimize', 'admin-notes' ); ?>"><?php echo $collapsed ? '&#9654;' : '&#9660;'; ?></button>
					<button class="admin-note-delete" title="<?php esc_attr_e( 'Delete', 'admin-notes' ); ?>">🗑</button>
				</div>
			</header>

			<div class="admin-note-body" <?php echo $collapsed ? 'style="display:none;"' : ''; ?>>
				<ul class="admin-note-checklist" data-note-id="<?php echo esc_attr( $post_id ); ?>">
					<?php
					if ( ! empty( $check ) ) {
						foreach ( $check as $item ) {
							$item_id  = isset( $item->id ) ? esc_attr( $item->id ) : '';
							$item_txt = isset( $item->text ) ? esc_html( $item->text ) : '';
							$done     = ! empty( $item->completed ) ? 'checked' : '';
							?>
							<li class="admin-note-check-item" data-item-id="<?php echo $item_id; ?>">
								<span class="check-drag">⋮</span>
								<label>
									<input type="checkbox" class="check-toggle" <?php echo $done; ?> />
									<span class="check-text"><?php echo $item_txt; ?></span>
								</label>
								<button class="check-remove" aria-label="<?php esc_attr_e( 'Remove task', 'admin-notes' ); ?>">✕</button>
							</li>
							<?php
						}
					}
					?>
				</ul>

				<div class="admin-note-add">
					<input type="text" class="admin-note-add-input" placeholder="<?php esc_attr_e( 'Add a task and press Enter', 'admin-notes' ); ?>" />
				</div>

				<div class="admin-note-footer">
					<div class="admin-note-colors" data-note-id="<?php echo esc_attr( $post_id ); ?>">
						<?php
						$presets = array(
							'#FFF9C4',
							'#FFE0B2',
							'#FFE6EE',
							'#E1F5FE',
							'#E8F5E9',
							'#F3E5F5',
							'#FFF3E0',
							'#FCE4EC',
							'#EDE7F6',
							'#F9FBE7',
						);
						foreach ( $presets as $preset ) {
							printf(
								'<button class="admin-note-color-swatch" data-color="%1$s" title="%1$s" style="background:%1$s"></button>',
								esc_attr( $preset )
							);
						}
						// color picker button
						echo '<input type="color" class="admin-note-color-picker" />';
						?>
					</div>

					<div class="admin-note-visibility">
						<select class="admin-note-visibility-select" data-note-id="<?php echo esc_attr( $post_id ); ?>">
							<option value="only_me" <?php selected( $visibility, 'only_me' ); ?>><?php esc_html_e( 'Only Me', 'admin-notes' ); ?></option>
							<option value="all_admins" <?php selected( $visibility, 'all_admins' ); ?>><?php esc_html_e( 'All Admins', 'admin-notes' ); ?></option>
							<option value="editors_and_above" <?php selected( $visibility, 'editors_and_above' ); ?>><?php esc_html_e( 'Editors & above', 'admin-notes' ); ?></option>
						</select>
					</div>






				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}


	// ============================= phase 8 ===========================

	// Save visibility mode
	public function ajax_admin_notes_update_visibility() {
			check_ajax_referer( 'admin_notes_nonce', 'security' );

			$note_id    = isset( $_POST['note_id'] ) ? intval( $_POST['note_id'] ) : 0;
			$visibility = isset( $_POST['visibility'] ) ? sanitize_text_field( $_POST['visibility'] ) : 'me';

		if ( $note_id && current_user_can( 'edit_post', $note_id ) ) {
			update_post_meta( $note_id, '_admin_note_visibility', $visibility );
			wp_send_json_success( array( 'saved' => true ) );
		} else {
			wp_send_json_error( array( 'saved' => false ) );
		}
	}

	// Save roles for notes
	public function ajax_admin_notes_update_roles() {
		check_ajax_referer( 'admin_notes_nonce', 'security' );

		$note_id = isset( $_POST['note_id'] ) ? intval( $_POST['note_id'] ) : 0;
		$roles   = isset( $_POST['roles'] ) ? array_map( 'sanitize_text_field', (array) $_POST['roles'] ) : array();

		if ( $note_id && current_user_can( 'edit_post', $note_id ) ) {
			update_post_meta( $note_id, '_admin_note_roles', $roles );
			wp_send_json_success( array( 'saved' => true ) );
		} else {
			wp_send_json_error( array( 'saved' => false ) );
		}
	}
}

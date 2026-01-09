<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Deletes the Custom Post Type, all associated posts, and post meta.
 *
 * @package plugmint-draggable-notes
 * @since 1.0.0
 * @author MD.Ridwan <ridwansweb@email.com>
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 1. Delete all posts of the custom post type
 */
$pdan_notes = get_posts(
	array(
		'post_type'      => 'pdan_admin_note',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'post_status'    => 'any',
	)
);

if ( $pdan_notes ) {
	foreach ( $pdan_notes as $pdan_note_id ) {
		wp_delete_post( $pdan_note_id, true );
	}
}

<?php
/**
 * Uninstall AI Patch Runner.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_clear_scheduled_hook( 'aipr_cleanup_old_backups' );

$aipr_settings = get_option( 'aipr_settings', array() );
if ( empty( $aipr_settings['cleanup_on_uninstall'] ) ) {
    return;
}

delete_option( 'aipr_settings' );
delete_option( 'aipr_action_log' );
delete_option( 'aipr_backup_log' );
delete_option( 'aipr_patch_library' );

$aipr_uploads = wp_upload_dir();
if ( empty( $aipr_uploads['error'] ) ) {
    $aipr_backup_root = trailingslashit( $aipr_uploads['basedir'] ) . 'aipr-backups';
    if ( is_dir( $aipr_backup_root ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        WP_Filesystem();
        if ( is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'rmdir' ) ) {
            $wp_filesystem->rmdir( $aipr_backup_root, true );
        }
    }
}

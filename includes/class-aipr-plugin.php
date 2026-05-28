<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once AIPR_DIR . 'includes/class-aipr-admin.php';

class AIPR_Plugin {
    public static function activate() {
        if ( ! wp_next_scheduled( 'aipr_cleanup_old_backups' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aipr_cleanup_old_backups' );
        }

        if ( ! get_option( AIPR_Admin::SETTINGS_OPTION ) ) {
            add_option(
                AIPR_Admin::SETTINGS_OPTION,
                array(
                    'allow_production_apply' => 0,
                    'store_preview_library'  => 1,
                    'cleanup_on_uninstall'   => 0,
                    'backup_retention_days'  => 30,
                ),
                '',
                false
            );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'aipr_cleanup_old_backups' );
    }

    public function boot() {

        if ( is_admin() ) {
            $admin = new AIPR_Admin();
            $admin->boot();
        }

        add_action( 'admin_init', array( $this, 'register_privacy_content' ) );
    }



    public function register_privacy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = '<p>' . esc_html__( 'AI Patch Runner kan logregels, patchbibliotheek-items en back-upmetadata opslaan zodat beheerders wijzigingen veilig kunnen bekijken, toepassen en terugdraaien.', 'ai-patch-runner' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Als patchback-ups zijn ingeschakeld en aangemaakt, kunnen back-upbestanden in de WordPress uploads-map worden opgeslagen totdat ze verlopen of door een beheerder worden verwijderd.', 'ai-patch-runner' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Deze plugin is bedoeld voor sitebeheerders en maakt geen publieke tracking- of marketingprofielen aan.', 'ai-patch-runner' ) . '</p>';

        wp_add_privacy_policy_content(
            __( 'AI Patch Runner', 'ai-patch-runner' ),
            wp_kses_post( wpautop( $content ) )
        );
    }
}

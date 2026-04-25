<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPR_Admin {
    const PREVIEW_KEY   = 'aipr_preview_';
    const LOG_OPTION    = 'aipr_action_log';
    const BACKUP_OPTION = 'aipr_backup_log';
    const LIBRARY_OPTION = 'aipr_patch_library';
    const SETTINGS_OPTION = 'aipr_settings';

    private $page_hooks = array();

    public function boot() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings_api' ) );
        add_action( 'aipr_cleanup_old_backups', array( $this, 'maybe_cleanup_old_backups' ) );

        add_action( 'admin_post_aipr_preview_patch', array( $this, 'handle_preview' ) );
        add_action( 'admin_post_aipr_apply_patch', array( $this, 'handle_apply' ) );
        add_action( 'admin_post_aipr_rollback_backup', array( $this, 'handle_rollback' ) );
        add_action( 'admin_post_aipr_save_patch_library', array( $this, 'handle_save_patch_library' ) );
        add_action( 'admin_post_aipr_delete_patch_library', array( $this, 'handle_delete_patch_library' ) );
        add_action( 'admin_post_aipr_export_patch_library', array( $this, 'handle_export_patch_library' ) );
    }

    public function register_menu() {
        $capability = 'manage_options';
        $slug       = 'ai-patch-runner';

        $this->page_hooks[] = add_menu_page(
            __( 'AI Patch Runner', 'ai-patch-runner' ),
            __( 'AI Patch Runner', 'ai-patch-runner' ),
            $capability,
            $slug,
            array( $this, 'render_dashboard_page' ),
            'dashicons-admin-tools',
            58
        );

        $this->page_hooks[] = add_submenu_page( $slug, __( 'Dashboard', 'ai-patch-runner' ), __( 'Dashboard', 'ai-patch-runner' ), $capability, $slug, array( $this, 'render_dashboard_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Patch toepassen', 'ai-patch-runner' ), __( 'Patch toepassen', 'ai-patch-runner' ), $capability, 'ai-patch-runner-apply', array( $this, 'render_apply_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Patchbibliotheek', 'ai-patch-runner' ), __( 'Patchbibliotheek', 'ai-patch-runner' ), $capability, 'ai-patch-runner-library', array( $this, 'render_library_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Plugin-inspecteur', 'ai-patch-runner' ), __( 'Plugin-inspecteur', 'ai-patch-runner' ), $capability, 'ai-patch-runner-inspector', array( $this, 'render_inspector_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Back-ups', 'ai-patch-runner' ), __( 'Back-ups', 'ai-patch-runner' ), $capability, 'ai-patch-runner-backups', array( $this, 'render_backups_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Activiteit', 'ai-patch-runner' ), __( 'Activiteit', 'ai-patch-runner' ), $capability, 'ai-patch-runner-activity', array( $this, 'render_activity_page' ) );
        $this->page_hooks[] = add_submenu_page( $slug, __( 'Instellingen', 'ai-patch-runner' ), __( 'Instellingen', 'ai-patch-runner' ), $capability, 'ai-patch-runner-settings', array( $this, 'render_settings_page' ) );

    }

    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, $this->page_hooks, true ) ) {
            return;
        }

        wp_enqueue_style( 'aipr-admin', AIPR_URL . 'assets/admin.css', array(), AIPR_VERSION );
        wp_enqueue_script( 'aipr-admin', AIPR_URL . 'assets/admin.js', array(), AIPR_VERSION, true );
        wp_localize_script(
            'aipr-admin',
            'aiprAdmin',
            array(
                'copied'      => __( 'Gekopieerd', 'ai-patch-runner' ),
                'copy'        => __( 'Kopieer', 'ai-patch-runner' ),
                'copy_failed' => __( 'Kopiëren mislukt. Kopieer de JSON handmatig.', 'ai-patch-runner' ),
            )
        );
    }


    private function guard_page_access() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te openen.', 'ai-patch-runner' ) );
        }
    }

    private function get_settings() {
        $defaults = array(
            'allow_production_apply' => 0,
            'store_preview_library'  => 1,
            'cleanup_on_uninstall'   => 0,
            'backup_retention_days'  => 30,
        );
        $saved = get_option( self::SETTINGS_OPTION, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, $defaults );
    }

    private function get_plugins_list() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        uasort(
            $plugins,
            static function ( $a, $b ) {
                return strcasecmp( $a['Name'] ?? '', $b['Name'] ?? '' );
            }
        );
        return $plugins;
    }

    private function get_notice() {
        if ( empty( $_GET['aipr_notice'] ) ) {
            return null;
        }

        $message = isset( $_GET['aipr_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aipr_message'] ) ) : '';
        if ( '' !== $message ) {
            $message = rawurldecode( $message );
        }

        return array(
            'type'    => sanitize_key( wp_unslash( $_GET['aipr_notice'] ) ),
            'message' => $message,
        );
    }

    private function get_preview_data() {
        return get_transient( self::PREVIEW_KEY . get_current_user_id() );
    }

    private function set_preview_data( $data ) {
        set_transient( self::PREVIEW_KEY . get_current_user_id(), $data, 30 * MINUTE_IN_SECONDS );
    }

    private function clear_preview_data() {
        delete_transient( self::PREVIEW_KEY . get_current_user_id() );
    }

    private function get_library() {
        $items = get_option( self::LIBRARY_OPTION, array() );
        return is_array( $items ) ? $items : array();
    }

    private function set_library( $items ) {
        update_option( self::LIBRARY_OPTION, array_values( $items ), false );
    }

    private function get_recent_log() {
        $items = get_option( self::LOG_OPTION, array() );
        return is_array( $items ) ? $items : array();
    }

    private function push_log_entry( $entry ) {
        $items = $this->get_recent_log();
        array_unshift( $items, $entry );
        update_option( self::LOG_OPTION, array_slice( $items, 0, 100 ), false );
    }

    private function get_recent_backups() {
        $items = get_option( self::BACKUP_OPTION, array() );
        return is_array( $items ) ? $items : array();
    }

    private function push_backup_entry( $entry ) {
        $items = $this->get_recent_backups();
        array_unshift( $items, $entry );
        update_option( self::BACKUP_OPTION, array_slice( $items, 0, 120 ), false );
    }

    private function page_url( $slug ) {
        return admin_url( 'admin.php?page=' . $slug );
    }

    private function current_slug() {
        return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'ai-patch-runner';
    }

    private function render_notice( $type, $message, $dismissible = false ) {
        $allowed = array( 'success', 'error', 'warning', 'info' );
        $type    = in_array( $type, $allowed, true ) ? $type : 'info';
        ?>
        <div class="aipr-page-notice aipr-page-notice--<?php echo esc_attr( $type ); ?><?php echo $dismissible ? ' is-dismissible' : ''; ?>"<?php echo $dismissible ? ' data-aipr-dismissible="true"' : ''; ?> role="status" aria-live="polite">
            <div class="aipr-page-notice__body">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <?php if ( $dismissible ) : ?>
                <button type="button" class="aipr-page-notice__dismiss" data-aipr-dismiss-notice="true" aria-label="<?php echo esc_attr__( 'Melding sluiten', 'ai-patch-runner' ); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_shell_start( $title, $eyebrow = '' ) {
        $this->guard_page_access();
        $notice = $this->get_notice();
        $preview = $this->get_preview_data();
        $plugins = $this->get_plugins_list();
        $settings = $this->get_settings();
        $environment = wp_get_environment_type();
        $library_count = count( $this->get_library() );
        $backup_count = count( $this->get_recent_backups() );
        $log_count = count( $this->get_recent_log() );
        $current = $this->current_slug();
        ?>
        <div class="wrap aipr-wrap">
            <div class="screen-reader-text aipr-live-region" id="aipr-live-region" aria-live="polite" aria-atomic="true"></div>
            <?php if ( $notice ) : ?>
                <?php $this->render_notice( $notice['type'], $notice['message'], true ); ?>
            <?php endif; ?>
            <?php if ( 'ai-patch-runner-settings' === $current && isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) : ?>
                <?php $this->render_notice( 'success', __( 'Instellingen opgeslagen.', 'ai-patch-runner' ), true ); ?>
            <?php endif; ?>
            <header class="aipr-topbar">
                <div>
                    <?php if ( $eyebrow ) : ?><div class="aipr-eyebrow"><?php echo esc_html( $eyebrow ); ?></div><?php endif; ?>
                    <h1><?php echo esc_html( $title ); ?></h1>
                    <div class="aipr-top-meta">
                        <span class="aipr-chip"><?php echo esc_html( sprintf( __( '%d plugins gedetecteerd', 'ai-patch-runner' ), count( $plugins ) ) ); ?></span>
                        <span class="aipr-chip <?php echo ! empty( $preview ) && empty( $preview['has_errors'] ) ? 'is-success' : 'is-tonal'; ?>"><?php echo ! empty( $preview ) ? esc_html__( 'Voorbeeld geladen', 'ai-patch-runner' ) : esc_html__( 'Geen voorbeeld geladen', 'ai-patch-runner' ); ?></span>
                        <span class="aipr-chip"><?php echo esc_html( strtoupper( $environment ) ); ?></span>
                        <?php if ( 'production' === $environment && empty( $settings['allow_production_apply'] ) ) : ?>
                            <span class="aipr-chip is-error"><?php esc_html_e( 'Toepassen op productie vergrendeld', 'ai-patch-runner' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="aipr-top-actions">
                    <a class="button aipr-button-secondary" href="<?php echo esc_url( AIPR_URL . 'examples/patch-template.json' ); ?>" download><?php esc_html_e( 'JSON-sjabloon downloaden', 'ai-patch-runner' ); ?></a>
                </div>
            </header>

            <nav class="aipr-nav" aria-label="<?php echo esc_attr__( 'AI Patch Runner-secties', 'ai-patch-runner' ); ?>">
                <?php
                $nav = array(
                    'ai-patch-runner' => __( 'Dashboard', 'ai-patch-runner' ),
                    'ai-patch-runner-apply' => __( 'Patch toepassen', 'ai-patch-runner' ),
                    'ai-patch-runner-library' => sprintf( __( 'Patchbibliotheek (%d)', 'ai-patch-runner' ), $library_count ),
                    'ai-patch-runner-inspector' => __( 'Plugin-inspecteur', 'ai-patch-runner' ),
                    'ai-patch-runner-backups' => sprintf( __( 'Backups (%d)', 'ai-patch-runner' ), $backup_count ),
                    'ai-patch-runner-activity' => sprintf( __( 'Activiteit (%d)', 'ai-patch-runner' ), $log_count ),
                    'ai-patch-runner-settings' => __( 'Instellingen', 'ai-patch-runner' ),
                );
                foreach ( $nav as $slug => $label ) :
                    ?>
                    <a class="aipr-nav-link <?php echo $slug === $current ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->page_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
        <?php
    }

    private function render_shell_end() {
        echo '</div>';
    }

    public function render_dashboard_page() {
        $this->render_shell_start( __( 'AI Patch Runner', 'ai-patch-runner' ), __( 'Veiligere patch-workflows voor WordPress-plugins', 'ai-patch-runner' ) );
        $plugins = $this->get_plugins_list();
        $preview = $this->get_preview_data();
        $library = $this->get_library();
        $logs = array_slice( $this->get_recent_log(), 0, 5 );
        $backups = array_slice( $this->get_recent_backups(), 0, 5 );
        ?>
        <main id="aipr-main-content" class="aipr-page-stack">
        <div class="aipr-grid aipr-grid--dashboard">
            <section class="aipr-card aipr-span-2">
                <div class="aipr-card-head">
                    <div>
                        <h2><?php esc_html_e( 'Wat deze plugin doet', 'ai-patch-runner' ); ?></h2>
                        <p><?php esc_html_e( 'Bekijk, valideer, maak een voorbeeld, pas toe, zet terug en archiveer plugin-patches zonder handmatig bestanden te wijzigen.', 'ai-patch-runner' ); ?></p>
                    </div>
                </div>
                <div class="aipr-kpi-grid">
                    <div class="aipr-kpi"><span><?php esc_html_e( 'Geïnstalleerde plugins', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) count( $plugins ) ); ?></strong></div>
                    <div class="aipr-kpi"><span><?php esc_html_e( 'Opgeslagen patches', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) count( $library ) ); ?></strong></div>
                    <div class="aipr-kpi"><span><?php esc_html_e( 'Back-ups', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) count( $backups ) ); ?></strong></div>
                    <div class="aipr-kpi"><span><?php esc_html_e( 'Voorbeeld gereed', 'ai-patch-runner' ); ?></span><strong><?php echo ! empty( $preview ) ? esc_html__( 'Ja', 'ai-patch-runner' ) : esc_html__( 'Nee', 'ai-patch-runner' ); ?></strong></div>
                </div>
                <div class="aipr-feature-grid">
                    <div class="aipr-feature-card"><h3><?php esc_html_e( 'Validatie (dry-run)', 'ai-patch-runner' ); ?></h3><p><?php esc_html_e( 'Elke patch wordt eerst gevalideerd met exact-match checks, een risicoscore en een PHP lint-simulatie voor gewijzigde PHP-bestanden.', 'ai-patch-runner' ); ?></p></div>
                    <div class="aipr-feature-card"><h3><?php esc_html_e( 'Visuele diff-voorvertoning', 'ai-patch-runner' ); ?></h3><p><?php esc_html_e( 'Elke operatie toont het exacte doelbestand plus regel-voor-regel vóór/na-output zodat je veilig kunt controleren.', 'ai-patch-runner' ); ?></p></div>
                    <div class="aipr-feature-card"><h3><?php esc_html_e( 'Terugdraaien en back-ups', 'ai-patch-runner' ); ?></h3><p><?php esc_html_e( 'Elke wijziging maakt een herstelbare back-up die je kunt terugzetten via de Back-ups-pagina.', 'ai-patch-runner' ); ?></p></div>
                    <div class="aipr-feature-card"><h3><?php esc_html_e( 'Inspecteur en bibliotheek', 'ai-patch-runner' ); ?></h3><p><?php esc_html_e( 'Scan de pluginstructuur, spot review-waardige patronen en bewaar herbruikbare patchpakketten in je patchbibliotheek.', 'ai-patch-runner' ); ?></p></div>
                </div>
            </section>
            <section class="aipr-card aipr-card--inspector-form">
                <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Snelle acties', 'ai-patch-runner' ); ?></h2></div></div>
                <div class="aipr-stack">
                    <a class="button button-primary" href="<?php echo esc_url( $this->page_url( 'ai-patch-runner-apply' ) ); ?>"><?php esc_html_e( 'Nieuw voorbeeld starten', 'ai-patch-runner' ); ?></a>
                    <a class="button aipr-button-secondary" href="<?php echo esc_url( $this->page_url( 'ai-patch-runner-library' ) ); ?>"><?php esc_html_e( 'Patchbibliotheek openen', 'ai-patch-runner' ); ?></a>
                    <a class="button aipr-button-secondary" href="<?php echo esc_url( $this->page_url( 'ai-patch-runner-inspector' ) ); ?>"><?php esc_html_e( 'Plugin inspecteren', 'ai-patch-runner' ); ?></a>
                </div>
            </section>
            <section class="aipr-card">
                <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Recente activiteit', 'ai-patch-runner' ); ?></h2></div></div>
                <?php if ( empty( $logs ) ) : ?><p class="aipr-muted"><?php esc_html_e( 'Nog geen patches toegepast.', 'ai-patch-runner' ); ?></p><?php else : ?>
                    <div class="aipr-list">
                        <?php foreach ( $logs as $entry ) : ?>
                            <article class="aipr-list-item"><strong><?php echo esc_html( $entry['package_name'] ); ?></strong><span><?php echo esc_html( $entry['plugin'] ); ?></span><span><?php echo esc_html( $entry['date'] ); ?></span></article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="aipr-card aipr-span-2">
                <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Recente back-ups', 'ai-patch-runner' ); ?></h2></div></div>
                <?php if ( empty( $backups ) ) : ?><p class="aipr-muted"><?php esc_html_e( 'Nog geen back-ups beschikbaar.', 'ai-patch-runner' ); ?></p><?php else : ?>
                    <div class="aipr-table-wrap"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Bestand', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Plugin', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Datum', 'ai-patch-runner' ); ?></th></tr></thead><tbody><?php foreach ( $backups as $backup ) : ?><tr><td><code><?php echo esc_html( basename( $backup['file'] ) ); ?></code></td><td><?php echo esc_html( $backup['plugin'] ); ?></td><td><?php echo esc_html( $backup['date'] ); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </section>
        </div>
        </main>
        <?php
        $this->render_shell_end();
    }

    public function render_apply_page() {
        $this->render_shell_start( __( 'Patch toepassen', 'ai-patch-runner' ), __( 'Importeer, bekijk en pas een gecontroleerd patchpakket toe', 'ai-patch-runner' ) );
        $plugins = $this->get_plugins_list();
        $preview = $this->get_preview_data();
        $selected = $preview['selected_plugin'] ?? '';
        $grouped = ! empty( $preview['checks'] ) ? $this->group_checks_by_file( $preview['checks'] ) : array();
        $schema_json = wp_json_encode( $this->get_patch_schema_example(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $risk = $preview['risk'] ?? array( 'score' => 0, 'label' => __( 'Unknown', 'ai-patch-runner' ) );
        ?>
        <main id="aipr-main-content" class="aipr-page-stack">
        <div class="aipr-grid aipr-grid--apply">
            <div class="aipr-main-col">
                <section class="aipr-card">
                    <div class="aipr-card-head"><div><h2><?php esc_html_e( '1. Selecteer plugin en importeer patch', 'ai-patch-runner' ); ?></h2><p><?php esc_html_e( 'Kies één geïnstalleerde plugin, upload één gecontroleerd JSON-patchpakket en voer een veilige droogloop-preview uit.', 'ai-patch-runner' ); ?></p></div></div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="aipr-stack">
                        <input type="hidden" name="action" value="aipr_preview_patch" />
                        <?php wp_nonce_field( 'aipr_preview_patch', 'aipr_preview_nonce' ); ?>
                        <div class="aipr-form-grid aipr-form-grid--inspector">
                            <div class="aipr-field">
                                <label for="aipr_target_plugin"><?php esc_html_e( 'Doelplugin', 'ai-patch-runner' ); ?></label>
                                <select id="aipr_target_plugin" name="target_plugin" required>
                                    <option value=""><?php esc_html_e( 'Selecteer een geïnstalleerde plugin', 'ai-patch-runner' ); ?></option>
                                    <?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>
                                        <option value="<?php echo esc_attr( $plugin_file ); ?>" <?php selected( $selected, $plugin_file ); ?>><?php echo esc_html( $plugin_data['Name'] . ' — ' . $plugin_file ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="aipr-field">
                                <label for="aipr_patch_file"><?php esc_html_e( 'Patchpakket (.json)', 'ai-patch-runner' ); ?></label>
                                <input id="aipr_patch_file" type="file" name="patch_file" accept="application/json,.json" required />
                            </div>
                        </div>
                        <p class="aipr-muted"><?php esc_html_e( 'Tijdens het voorbeeld wordt niets gewijzigd. De droogloop controleert exact matches, veilige paden, basis-PHP-syntax en een risicoscore.', 'ai-patch-runner' ); ?></p>
                        <div class="aipr-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Patchvoorbeeld bekijken', 'ai-patch-runner' ); ?></button></div>
                    </form>
                </section>


                <section class="aipr-card">
                    <div class="aipr-card-head aipr-card-head--between"><div><h2><?php esc_html_e( '2. JSON patch-formaat', 'ai-patch-runner' ); ?></h2><p><?php esc_html_e( 'Gebruik expliciete operaties zodat het voorbeeld elke bestandswijziging exact kan valideren.', 'ai-patch-runner' ); ?></p></div><button type="button" class="button aipr-button-secondary aipr-copy-button" data-copy-target="#aipr-schema-json"><?php esc_html_e( 'JSON-voorbeeld kopiëren', 'ai-patch-runner' ); ?></button></div>
                    <div class="aipr-form-grid aipr-form-grid--schema">
                        <div class="aipr-inline-card">
                            <span class="aipr-mini-label"><?php esc_html_e( 'Required keys', 'ai-patch-runner' ); ?></span>
                            <ul class="aipr-pill-list"><li><code>format_version</code></li><li><code>package_name</code></li><li><code>description</code></li><li><code>target.plugin</code></li><li><code>operations[]</code></li></ul>
                            <span class="aipr-mini-label"><?php esc_html_e( 'Ondersteunde operaties', 'ai-patch-runner' ); ?></span>
                            <ul class="aipr-pill-list"><li><code>replace_once</code></li><li><code>insert_before_once</code></li><li><code>insert_after_once</code></li><li><code>remove_once</code></li><li><code>write_file_if_missing</code></li></ul>
                        </div>
                        <textarea id="aipr-schema-json" class="code aipr-schema-output" rows="18" readonly><?php echo esc_textarea( $schema_json ); ?></textarea>
                    </div>
                </section>

                <?php if ( ! empty( $preview ) ) : ?>
                    <section class="aipr-card">
                        <div class="aipr-card-head"><div><h2><?php esc_html_e( '3. Voorbeeld bekijken en toepassen', 'ai-patch-runner' ); ?></h2><p><?php esc_html_e( 'Controleer de exacte bestanden, operaties, status en diff-output voordat je iets toepast.', 'ai-patch-runner' ); ?></p></div></div>
                        <div class="aipr-kpi-grid aipr-kpi-grid--preview">
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Bestanden', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) count( $grouped ) ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Operaties', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) count( $preview['checks'] ) ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Risico', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( $risk['label'] ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Doelplugin', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( $selected ); ?></strong></div>
                        </div>
                        <?php if ( ! empty( $preview['package']['description'] ) ) : ?><p class="aipr-muted aipr-space-top"><?php echo esc_html( $preview['package']['description'] ); ?></p><?php endif; ?>
                        <div class="aipr-stack aipr-space-top">
                            <?php foreach ( $grouped as $file_path => $checks ) : ?>
                                <article class="aipr-file-card">
                                    <div class="aipr-file-header"><div><span class="aipr-mini-label"><?php esc_html_e( 'Bestand', 'ai-patch-runner' ); ?></span><h3><code><?php echo esc_html( $file_path ); ?></code></h3></div><span class="aipr-chip"><?php echo esc_html( sprintf( _n( '%d operation', '%d operations', count( $checks ), 'ai-patch-runner' ), count( $checks ) ) ); ?></span></div>
                                    <div class="aipr-stack">
                                        <?php foreach ( $checks as $check ) : ?>
                                            <div class="aipr-change-card <?php echo ! empty( $check['ok'] ) ? 'is-valid' : 'is-invalid'; ?>">
                                                <div class="aipr-change-head"><div><span class="aipr-chip"><?php echo esc_html( $this->get_operation_label( $check['type'] ) ); ?></span><p class="aipr-muted aipr-change-text"><?php echo esc_html( $check['summary'] ); ?></p></div><span class="aipr-chip <?php echo ! empty( $check['ok'] ) ? 'is-success' : 'is-error'; ?>"><?php echo esc_html( $check['message'] ); ?></span></div>
                                                <?php echo $this->render_diff_preview( $check ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                <?php if ( ! empty( $check['lint_message'] ) ) : ?><p class="aipr-muted aipr-lint-note"><?php echo esc_html( $check['lint_message'] ); ?></p><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="aipr-actions aipr-actions--split aipr-space-top">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="aipr_save_patch_library" />
                                <?php wp_nonce_field( 'aipr_save_patch_library', 'aipr_library_nonce' ); ?>
                                <button class="button aipr-button-secondary" type="submit"><?php esc_html_e( 'Voorbeeld opslaan in patchbibliotheek', 'ai-patch-runner' ); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aipr-inline-form">
                                <input type="hidden" name="action" value="aipr_apply_patch" />
                                <?php wp_nonce_field( 'aipr_apply_patch', 'aipr_apply_nonce' ); ?>
                                <label class="aipr-review-check"><input type="checkbox" name="confirmed_review" value="1" required /> <?php esc_html_e( 'Ik heb het voorbeeld gecontroleerd en wil deze patch toepassen.', 'ai-patch-runner' ); ?></label>
                                <button class="button button-primary" type="submit" <?php disabled( ! empty( $preview['has_errors'] ) ); ?>><?php esc_html_e( 'Patch nu toepassen', 'ai-patch-runner' ); ?></button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="aipr-side-col">
                <section class="aipr-card"><div class="aipr-card-head"><div><h2><?php esc_html_e( 'Safety checks', 'ai-patch-runner' ); ?></h2></div></div><ul class="aipr-check-list"><li><?php esc_html_e( 'Exact-match validatie per operatie', 'ai-patch-runner' ); ?></li><li><?php esc_html_e( 'Padbeveiliging vergrendeld op de map van de geselecteerde plugin', 'ai-patch-runner' ); ?></li><li><?php esc_html_e( 'PHP lint-simulatie voor gewijzigde PHP-bestanden', 'ai-patch-runner' ); ?></li><li><?php esc_html_e( 'Automatische back-up vóór elke wijziging', 'ai-patch-runner' ); ?></li></ul></section>
                <section class="aipr-card"><div class="aipr-card-head"><div><h2><?php esc_html_e( 'Risicoscore', 'ai-patch-runner' ); ?></h2></div></div><p class="aipr-muted"><?php esc_html_e( 'Root-pluginbestanden, verwijder-operaties, nieuwe bestanden en veel schrijfacties verhogen de risicoscore van het voorbeeld.', 'ai-patch-runner' ); ?></p></section>
            </aside>
        </div>
        </main>
        <?php
        $this->render_shell_end();
    }

    public function render_library_page() {
        $this->render_shell_start( __( 'Patchbibliotheek', 'ai-patch-runner' ), __( 'Herbruikbare patchpakketten die je vanuit voorbeelden hebt opgeslagen', 'ai-patch-runner' ) );
        $library = $this->get_library();
        ?>
        <section class="aipr-card">
            <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Opgeslagen patches', 'ai-patch-runner' ); ?></h2><p><?php esc_html_e( 'Bewaar gecontroleerde patchpakketten voor hergebruik, export of latere vergelijking.', 'ai-patch-runner' ); ?></p></div></div>
            <?php if ( empty( $library ) ) : ?>
                <p class="aipr-muted"><?php esc_html_e( 'Nog geen patches opgeslagen. Maak eerst een voorbeeld en sla die daarna op in de bibliotheek.', 'ai-patch-runner' ); ?></p>
            <?php else : ?>
                <div class="aipr-stack">
                    <?php foreach ( $library as $item ) : ?>
                        <article class="aipr-list-card">
                            <div class="aipr-list-card-head">
                                <div><h3><?php echo esc_html( $item['package_name'] ); ?></h3><p class="aipr-muted"><?php echo esc_html( $item['description'] ); ?></p></div>
                                <div class="aipr-chip-row"><span class="aipr-chip"><?php echo esc_html( $item['plugin'] ); ?></span><span class="aipr-chip"><?php echo esc_html( sprintf( _n( '%d operation', '%d operations', count( $item['package']['operations'] ), 'ai-patch-runner' ), count( $item['package']['operations'] ) ) ); ?></span></div>
                            </div>
                            <div class="aipr-actions aipr-actions--split">
                                <small class="aipr-muted"><?php echo esc_html( $item['date'] ); ?></small>
                                <div class="aipr-chip-row">
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="aipr_export_patch_library" />
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
                                        <?php wp_nonce_field( 'aipr_export_patch_library', 'aipr_library_nonce' ); ?>
                                        <button class="button aipr-button-secondary" type="submit"><?php esc_html_e( 'Export JSON', 'ai-patch-runner' ); ?></button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Deze opgeslagen patch verwijderen?');">
                                        <input type="hidden" name="action" value="aipr_delete_patch_library" />
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
                                        <?php wp_nonce_field( 'aipr_delete_patch_library', 'aipr_library_nonce' ); ?>
                                        <button class="button aipr-button-secondary" type="submit"><?php esc_html_e( 'Verwijderen', 'ai-patch-runner' ); ?></button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        $this->render_shell_end();
    }

    public function render_inspector_page() {
        $this->render_shell_start( __( 'Plugin-inspecteur', 'ai-patch-runner' ), __( 'Breng bestanden, hooks en risico’s in kaart vóór je patcht', 'ai-patch-runner' ) );

        $plugins  = $this->get_plugins_list();
        $selected = isset( $_GET['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin_file'] ) ) : '';
        $report   = ( $selected && isset( $plugins[ $selected ] ) ) ? $this->inspect_plugin( $selected ) : null;
        ?>
        <div class="aipr-page-stack">
            <section class="aipr-card">
                <div class="aipr-card-head">
                    <div>
                        <h2><?php esc_html_e( 'Plugin inspecteren', 'ai-patch-runner' ); ?></h2>
                        <p><?php esc_html_e( 'Scan PHP-bestanden, hooks, classes en review-waardige functies voordat je een patch bouwt.', 'ai-patch-runner' ); ?></p>
                    </div>
                </div>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="aipr-stack">
                    <input type="hidden" name="page" value="ai-patch-runner-inspector" />
                    <div class="aipr-form-grid aipr-form-grid--inspector-submit">
                        <div class="aipr-field">
                            <label for="aipr_inspector_plugin"><?php esc_html_e( 'Plugin', 'ai-patch-runner' ); ?></label>
                            <select id="aipr_inspector_plugin" name="plugin_file" required>
                                <option value=""><?php esc_html_e( 'Selecteer een geïnstalleerde plugin', 'ai-patch-runner' ); ?></option>
                                <?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>
                                    <option value="<?php echo esc_attr( $plugin_file ); ?>" <?php selected( $selected, $plugin_file ); ?>><?php echo esc_html( $plugin_data['Name'] . ' — ' . $plugin_file ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="aipr-actions aipr-actions--align-end">
                            <button class="button button-primary" type="submit"><?php esc_html_e( 'Plugin inspecteren', 'ai-patch-runner' ); ?></button>
                        </div>
                    </div>
                </form>
            </section>

            <?php if ( $report ) : ?>
                <div class="aipr-grid aipr-grid--dashboard aipr-grid--inspector-report">
                    <section class="aipr-card aipr-card--inspector-summary aipr-span-2">
                        <div class="aipr-card-head">
                            <div>
                                <h2><?php esc_html_e( 'Samenvatting inspecteur', 'ai-patch-runner' ); ?></h2>
                                <p><?php esc_html_e( 'Overzichtstellingen om de patch-omvang en review-scope in te schatten.', 'ai-patch-runner' ); ?></p>
                            </div>
                        </div>
                        <div class="aipr-kpi-grid">
                            <div class="aipr-kpi"><span><?php esc_html_e( 'PHP-bestanden', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) $report['php_files'] ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Hooks', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) $report['hooks'] ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Functions', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) $report['functions'] ); ?></strong></div>
                            <div class="aipr-kpi"><span><?php esc_html_e( 'Classes', 'ai-patch-runner' ); ?></span><strong><?php echo esc_html( (string) $report['classes'] ); ?></strong></div>
                        </div>
                    </section>

                    <section class="aipr-card">
                        <div class="aipr-card-head">
                            <div>
                                <h2><?php esc_html_e( 'Review-waardige patronen', 'ai-patch-runner' ); ?></h2>
                                <p><?php esc_html_e( 'These patterns deserve manual review, but are not automatically unsafe in context.', 'ai-patch-runner' ); ?></p>
                            </div>
                        </div>
                        <?php if ( empty( $report['review_patterns'] ) ) : ?>
                            <p class="aipr-muted"><?php esc_html_e( 'Geen review-waardige patronen gevonden in de gescande bestanden.', 'ai-patch-runner' ); ?></p>
                        <?php else : ?>
                            <ul class="aipr-check-list">
                                <?php foreach ( $report['review_patterns'] as $item ) : ?>
                                    <li><code><?php echo esc_html( $item ); ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="aipr-card aipr-span-2">
                        <div class="aipr-card-head">
                            <div>
                                <h2><?php esc_html_e( 'Voorgestelde patchdoelen', 'ai-patch-runner' ); ?></h2>
                                <p><?php esc_html_e( 'Dit zijn waarschijnlijk patch-vriendelijke PHP-bestanden op basis van een gangbare pluginstructuur.', 'ai-patch-runner' ); ?></p>
                            </div>
                        </div>
                        <ul class="aipr-check-list">
                            <?php foreach ( $report['suggested_files'] as $item ) : ?>
                                <li><code><?php echo esc_html( $item ); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="aipr-card aipr-span-2">
                        <div class="aipr-card-head">
                            <div>
                                <h2><?php esc_html_e( 'Detected hooks', 'ai-patch-runner' ); ?></h2>
                                <p><?php esc_html_e( 'Gedetecteerde hook-namen helpen je om gerichte patches veiliger te verankeren.', 'ai-patch-runner' ); ?></p>
                            </div>
                        </div>
                        <div class="aipr-tag-cloud">
                            <?php foreach ( array_slice( $report['hook_names'], 0, 40 ) as $hook ) : ?>
                                <span class="aipr-chip"><code><?php echo esc_html( $hook ); ?></code></span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $this->render_shell_end();
    }

    public function render_backups_page() {
        $this->render_shell_start( __( 'Back-ups', 'ai-patch-runner' ), __( 'Vorige bestandsversies terugzetten die tijdens het toepassen zijn gemaakt', 'ai-patch-runner' ) );
        $backups = $this->get_recent_backups();
        ?>
        <section class="aipr-card">
            <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Back-upgeschiedenis', 'ai-patch-runner' ); ?></h2></div></div>
            <?php if ( empty( $backups ) ) : ?><p class="aipr-muted"><?php esc_html_e( 'Geen back-ups beschikbaar.', 'ai-patch-runner' ); ?></p><?php else : ?>
                <div class="aipr-table-wrap"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Bestand', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Plugin', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Datum', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Actie', 'ai-patch-runner' ); ?></th></tr></thead><tbody><?php foreach ( $backups as $backup ) : ?><tr><td><code><?php echo esc_html( $backup['file'] ); ?></code></td><td><?php echo esc_html( $backup['plugin'] ); ?></td><td><?php echo esc_html( $backup['date'] ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="aipr_rollback_backup" /><input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup['id'] ); ?>" /><?php wp_nonce_field( 'aipr_rollback_backup', 'aipr_rollback_nonce' ); ?><button class="button aipr-button-secondary" type="submit"><?php esc_html_e( 'Rollback', 'ai-patch-runner' ); ?></button></form></td></tr><?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </section>
        <?php
        $this->render_shell_end();
    }

    public function render_activity_page() {
        $this->render_shell_start( __( 'Activiteit', 'ai-patch-runner' ), __( 'Auditlog van toegepaste patches', 'ai-patch-runner' ) );
        $log = $this->get_recent_log();
        ?>
        <section class="aipr-card">
            <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Patch-activiteitenlog', 'ai-patch-runner' ); ?></h2></div></div>
            <?php if ( empty( $log ) ) : ?><p class="aipr-muted"><?php esc_html_e( 'Nog geen patch-activiteit.', 'ai-patch-runner' ); ?></p><?php else : ?><div class="aipr-table-wrap"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Pakket', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Plugin', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Writes', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'User', 'ai-patch-runner' ); ?></th><th scope="col"><?php esc_html_e( 'Datum', 'ai-patch-runner' ); ?></th></tr></thead><tbody><?php foreach ( $log as $entry ) : ?><tr><td><?php echo esc_html( $entry['package_name'] ); ?></td><td><?php echo esc_html( $entry['plugin'] ); ?></td><td><?php echo esc_html( (string) $entry['writes'] ); ?></td><td><?php echo esc_html( $entry['user'] ); ?></td><td><?php echo esc_html( $entry['date'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <?php
        $this->render_shell_end();
    }

    public function register_settings_api() {
        register_setting(
            'aipr_settings_group',
            self::SETTINGS_OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => $this->get_settings(),
            )
        );

        add_settings_section(
            'aipr_settings_main',
            __( 'Patchworkflow-instellingen', 'ai-patch-runner' ),
            array( $this, 'render_settings_section_intro' ),
            'ai-patch-runner-settings'
        );

        add_settings_field(
            'aipr_allow_production_apply',
            __( 'Toepassen op productie', 'ai-patch-runner' ),
            array( $this, 'render_allow_production_field' ),
            'ai-patch-runner-settings',
            'aipr_settings_main'
        );

        add_settings_field(
            'aipr_store_preview_library',
            __( 'Patchbibliotheek', 'ai-patch-runner' ),
            array( $this, 'render_store_preview_library_field' ),
            'ai-patch-runner-settings',
            'aipr_settings_main'
        );

        add_settings_field(
            'aipr_cleanup_on_uninstall',
            __( 'Opschonen bij verwijderen', 'ai-patch-runner' ),
            array( $this, 'render_cleanup_on_uninstall_field' ),
            'ai-patch-runner-settings',
            'aipr_settings_main'
        );

        add_settings_field(
            'aipr_backup_retention_days',
            __( 'Back-up bewaartermijn', 'ai-patch-runner' ),
            array( $this, 'render_backup_retention_field' ),
            'ai-patch-runner-settings',
            'aipr_settings_main'
        );
    }

    public function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : array();

        return array(
            'allow_production_apply' => empty( $input['allow_production_apply'] ) ? 0 : 1,
            'store_preview_library'  => empty( $input['store_preview_library'] ) ? 0 : 1,
            'cleanup_on_uninstall'   => empty( $input['cleanup_on_uninstall'] ) ? 0 : 1,
            'backup_retention_days'  => max( 1, absint( $input['backup_retention_days'] ?? 30 ) ),
        );
    }

    public function render_settings_section_intro() {
        echo '<p class="aipr-muted">' . esc_html__( 'Productie is standaard vergrendeld. Zet dit alleen aan als je het risico begrijpt.', 'ai-patch-runner' ) . '</p>';
    }

    public function render_allow_production_field() {
        $settings = $this->get_settings();
        ?>
        <label class="aipr-toggle" for="aipr_allow_production_apply">
            <input id="aipr_allow_production_apply" type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[allow_production_apply]" value="1" <?php checked( ! empty( $settings['allow_production_apply'] ) ); ?> />
            <span><?php esc_html_e( 'Patch toepassen op productieomgevingen toestaan', 'ai-patch-runner' ); ?></span>
        </label>
        <?php
    }

    public function render_store_preview_library_field() {
        $settings = $this->get_settings();
        ?>
        <label class="aipr-toggle" for="aipr_store_preview_library">
            <input id="aipr_store_preview_library" type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[store_preview_library]" value="1" <?php checked( ! empty( $settings['store_preview_library'] ) ); ?> />
            <span><?php esc_html_e( 'Patchbibliotheek beschikbaar houden in admin', 'ai-patch-runner' ); ?></span>
        </label>
        <?php
    }

    public function render_cleanup_on_uninstall_field() {
        $settings = $this->get_settings();
        ?>
        <label class="aipr-toggle" for="aipr_cleanup_on_uninstall">
            <input id="aipr_cleanup_on_uninstall" type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[cleanup_on_uninstall]" value="1" <?php checked( ! empty( $settings['cleanup_on_uninstall'] ) ); ?> />
            <span><?php esc_html_e( 'Verwijder plugingegevens en back-ups wanneer deze plugin wordt verwijderd', 'ai-patch-runner' ); ?></span>
        </label>
        <?php
    }

    public function render_backup_retention_field() {
        $settings = $this->get_settings();
        ?>
        <div class="aipr-field aipr-field--compact">
            <label for="aipr_backup_retention_days"><?php esc_html_e( 'Back-up bewaartermijn (dagen)', 'ai-patch-runner' ); ?></label>
            <input id="aipr_backup_retention_days" type="number" min="1" step="1" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[backup_retention_days]" value="<?php echo esc_attr( (string) $settings['backup_retention_days'] ); ?>" inputmode="numeric" aria-describedby="aipr_backup_retention_days_description" />
            <p id="aipr_backup_retention_days_description" class="description aipr-muted"><?php esc_html_e( 'Oudere back-upbestanden worden automatisch verwijderd na zoveel dagen.', 'ai-patch-runner' ); ?></p>
        </div>
        <?php
    }

    public function render_settings_page() {
        $this->render_shell_start( __( 'Instellingen', 'ai-patch-runner' ), __( 'Omgevingsbeveiliging en workflow-standaarden', 'ai-patch-runner' ) );
        ?>
        <main id="aipr-main-content" class="aipr-page-stack">
        <section class="aipr-card aipr-settings-card">
            <div class="aipr-card-head"><div><h2><?php esc_html_e( 'Patchworkflow-instellingen', 'ai-patch-runner' ); ?></h2><p><?php esc_html_e( 'Gebruik WordPress-native instellingenvelden voor productie-beveiliging en workflow-standaarden.', 'ai-patch-runner' ); ?></p></div></div>
            <form method="post" action="options.php" class="aipr-stack">
                <?php
                settings_fields( 'aipr_settings_group' );
                do_settings_sections( 'ai-patch-runner-settings' );
                submit_button( __( 'Instellingen opslaan', 'ai-patch-runner' ), 'primary', 'submit', false );
                ?>
            </form>
        </section>
        </main>
        <?php
        $this->render_shell_end();
    }

    public function handle_preview() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_preview_patch', 'aipr_preview_nonce' );

        $selected_plugin = isset( $_POST['target_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['target_plugin'] ) ) : '';
        $plugins         = $this->get_plugins_list();

        if ( empty( $selected_plugin ) || ! isset( $plugins[ $selected_plugin ] ) ) {
            $this->redirect_notice( 'error', __( 'Selecteer een geldige geïnstalleerde plugin.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $file_error    = isset( $_FILES['patch_file']['error'] ) ? absint( $_FILES['patch_file']['error'] ) : UPLOAD_ERR_OK;
        $uploaded_tmp  = isset( $_FILES['patch_file']['tmp_name'] ) ? (string) $_FILES['patch_file']['tmp_name'] : '';
        $uploaded_name = isset( $_FILES['patch_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['patch_file']['name'] ) ) : '';
        $uploaded_size = isset( $_FILES['patch_file']['size'] ) ? absint( $_FILES['patch_file']['size'] ) : 0;
        $filetype      = wp_check_filetype_and_ext( $uploaded_tmp, $uploaded_name, array( 'json' => 'application/json' ) );

        if ( UPLOAD_ERR_OK !== $file_error ) {
            $this->redirect_notice( 'error', __( 'Het geüploade patchbestand kon niet worden verwerkt.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        if ( '' === $uploaded_tmp || ! is_uploaded_file( $uploaded_tmp ) || ! is_readable( $uploaded_tmp ) ) {
            $this->redirect_notice( 'error', __( 'Upload eerst een leesbaar JSON-patchpakket.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        if ( '.json' !== strtolower( substr( $uploaded_name, -5 ) ) || 'json' !== ( $filetype['ext'] ?? '' ) ) {
            $this->redirect_notice( 'error', __( 'Upload een geldig .json patchpakket.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        if ( $uploaded_size > 1024 * 1024 ) {
            $this->redirect_notice( 'error', __( 'Patchpakketten moeten 1 MB of kleiner zijn.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $raw = file_get_contents( $uploaded_tmp );
        if ( false === $raw ) {
            $this->redirect_notice( 'error', __( 'Kon het geüploade patchbestand niet lezen.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $package = json_decode( $raw, true );
        if ( ! is_array( $package ) ) {
            $this->redirect_notice( 'error', __( 'Ongeldig JSON-patchpakket.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $shape_errors = $this->validate_package_shape( $package );
        $checks       = array();

        foreach ( $shape_errors as $message ) {
            $checks[] = array(
                'type'      => 'package',
                'file'      => 'package',
                'ok'        => false,
                'message'   => $message,
                'summary'   => __( 'Validatie van de structuur van het patchpakket is mislukt.', 'ai-patch-runner' ),
                'diff_html' => '',
            );
        }

        if ( empty( $shape_errors ) ) {
            $checks = array_merge( $checks, $this->build_preview_checks( $selected_plugin, $package ) );
        }

        $has_errors = false;
        foreach ( $checks as $check ) {
            if ( empty( $check['ok'] ) ) {
                $has_errors = true;
                break;
            }
        }

        $risk = $this->calculate_risk( $package, $checks );
        $preview = array(
            'selected_plugin' => $selected_plugin,
            'package'         => $package,
            'checks'          => $checks,
            'has_errors'      => $has_errors,
            'risk'            => $risk,
        );
        $this->set_preview_data( $preview );

        $message = $has_errors ? __( 'Voorbeeld aangemaakt met validatieproblemen. Controleer de fouten vóór je toepast.', 'ai-patch-runner' ) : __( 'Voorbeeld succesvol aangemaakt.', 'ai-patch-runner' );
        $this->redirect_notice( $has_errors ? 'warning' : 'success', $message, 'ai-patch-runner-apply' );
    }

    public function handle_apply() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_apply_patch', 'aipr_apply_nonce' );

        $settings = $this->get_settings();
        if ( 'production' === wp_get_environment_type() && empty( $settings['allow_production_apply'] ) ) {
            $this->redirect_notice( 'error', __( 'Toepassen op productie is vergrendeld in de instellingen.', 'ai-patch-runner' ), 'ai-patch-runner-settings' );
        }

        if ( empty( $_POST['confirmed_review'] ) ) {
            $this->redirect_notice( 'error', __( 'Bevestig dat je eerst het voorbeeld hebt gecontroleerd.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $preview = $this->get_preview_data();
        if ( empty( $preview['package'] ) || empty( $preview['selected_plugin'] ) ) {
            $this->redirect_notice( 'error', __( 'Er is geen voorbeeld om toe te passen.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        if ( ! empty( $preview['has_errors'] ) ) {
            $this->redirect_notice( 'error', __( 'Los de validatieproblemen op voordat je de patch toepast.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $result = $this->apply_package( $preview['selected_plugin'], $preview['package'] );
        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'error', $result->get_error_message(), 'ai-patch-runner-apply' );
        }

        $user = wp_get_current_user();
        $this->push_log_entry(
            array(
                'package_name' => (string) ( $preview['package']['package_name'] ?? __( 'Naamloze patch', 'ai-patch-runner' ) ),
                'plugin'       => $preview['selected_plugin'],
                'writes'       => (int) $result['writes'],
                'user'         => $user && ! empty( $user->user_login ) ? $user->user_login : __( 'Unknown', 'ai-patch-runner' ),
                'date'         => wp_date( 'Y-m-d H:i' ),
            )
        );

        $this->clear_preview_data();
        $this->redirect_notice( 'success', __( 'Patch succesvol toegepast.', 'ai-patch-runner' ), 'ai-patch-runner-activity' );
    }

    public function handle_save_patch_library() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_save_patch_library', 'aipr_library_nonce' );
        $settings = $this->get_settings();
        if ( empty( $settings['store_preview_library'] ) ) {
            $this->redirect_notice( 'error', __( 'Patchbibliotheek is uitgeschakeld in de instellingen.', 'ai-patch-runner' ), 'ai-patch-runner-settings' );
        }

        $preview = $this->get_preview_data();
        if ( empty( $preview['package'] ) || empty( $preview['selected_plugin'] ) ) {
            $this->redirect_notice( 'error', __( 'Er is geen voorbeeld beschikbaar om op te slaan.', 'ai-patch-runner' ), 'ai-patch-runner-apply' );
        }

        $library = $this->get_library();
        $library[] = array(
            'id'          => wp_generate_uuid4(),
            'package_name'=> (string) ( $preview['package']['package_name'] ?? __( 'Naamloze patch', 'ai-patch-runner' ) ),
            'description' => (string) ( $preview['package']['description'] ?? '' ),
            'plugin'      => $preview['selected_plugin'],
            'package'     => $preview['package'],
            'date'        => wp_date( 'Y-m-d H:i' ),
        );
        $this->set_library( $library );
        $this->redirect_notice( 'success', __( 'Voorbeeld opgeslagen in de patchbibliotheek.', 'ai-patch-runner' ), 'ai-patch-runner-library' );
    }

    public function handle_delete_patch_library() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_delete_patch_library', 'aipr_library_nonce' );
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
        $library = array_values( array_filter( $this->get_library(), static function ( $item ) use ( $item_id ) { return ( $item['id'] ?? '' ) !== $item_id; } ) );
        $this->set_library( $library );
        $this->redirect_notice( 'success', __( 'Opgeslagen patch uit de bibliotheek verwijderd.', 'ai-patch-runner' ), 'ai-patch-runner-library' );
    }

    public function handle_export_patch_library() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_export_patch_library', 'aipr_library_nonce' );
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
        foreach ( $this->get_library() as $item ) {
            if ( ( $item['id'] ?? '' ) === $item_id ) {
                nocache_headers();
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $item['package_name'] ) . '.json"' );
                echo wp_json_encode( $item['package'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                exit;
            }
        }
        $this->redirect_notice( 'error', __( 'Opgeslagen patch niet gevonden.', 'ai-patch-runner' ), 'ai-patch-runner-library' );
    }

    public function handle_rollback() {
        $this->guard_page_access();
        check_admin_referer( 'aipr_rollback_backup', 'aipr_rollback_nonce' );
        $backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
        $backups = $this->get_recent_backups();
        foreach ( $backups as $backup ) {
            if ( ( $backup['id'] ?? '' ) !== $backup_id ) {
                continue;
            }
            $backup_path = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';
            if ( empty( $backup_path ) || empty( $backup['file'] ) || ! file_exists( $backup_path ) ) {
                $this->redirect_notice( 'error', __( 'Back-upbestand kon niet worden gevonden.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
            }
            if ( ! $this->is_safe_backup_path( $backup_path ) ) {
                $this->redirect_notice( 'error', __( 'Terugzetten is geblokkeerd omdat het back-uppad niet veilig is.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
            }
            $contents = file_get_contents( $backup_path );
            if ( false === $contents ) {
                $this->redirect_notice( 'error', __( 'Back-upbestand kon niet worden gelezen.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
            }

            $plugin_file = isset( $backup['plugin'] ) ? (string) $backup['plugin'] : '';
            $plugin_base = $plugin_file ? $this->get_plugin_base_dir( $plugin_file ) : '';
            if ( ! $plugin_base || ! $this->is_safe_path_in_dir( $backup['file'], $plugin_base, false ) ) {
                $this->redirect_notice( 'error', __( 'Terugzetten van de back-up is geblokkeerd omdat het doelpad niet veilig is.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
            }

            $result = $this->write_file_raw( $backup['file'], $contents );
            if ( is_wp_error( $result ) ) {
                $this->redirect_notice( 'error', $result->get_error_message(), 'ai-patch-runner-backups' );
            }
            $this->redirect_notice( 'success', __( 'Back-up succesvol teruggezet.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
        }
        $this->redirect_notice( 'error', __( 'Back-upitem niet gevonden.', 'ai-patch-runner' ), 'ai-patch-runner-backups' );
    }

    public function maybe_cleanup_old_backups() {
        // Runs from WP-Cron; do not require an interactive admin user.
        // The backup paths are revalidated before deletion.
        if ( get_transient( 'aipr_backup_cleanup_ran' ) ) {
            return;
        }
        set_transient( 'aipr_backup_cleanup_ran', 1, DAY_IN_SECONDS );

        $settings = $this->get_settings();
        $retention_days = max( 1, absint( $settings['backup_retention_days'] ?? 30 ) );
        $cutoff = time() - ( $retention_days * DAY_IN_SECONDS );
        $backups = $this->get_recent_backups();
        $kept = array();

        foreach ( $backups as $backup ) {
            $backup_path = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';
            $file_time = $backup_path && file_exists( $backup_path ) ? filemtime( $backup_path ) : 0;

            if ( $backup_path && ! $this->is_safe_backup_path( $backup_path ) ) {
                continue;
            }

            if ( $file_time && $file_time < $cutoff ) {
                wp_delete_file( $backup_path );
                continue;
            }

            if ( $backup_path && ! file_exists( $backup_path ) ) {
                continue;
            }

            $kept[] = $backup;
        }

        if ( count( $kept ) !== count( $backups ) ) {
            update_option( self::BACKUP_OPTION, array_slice( $kept, 0, 120 ), false );
        }
    }

    private function get_patch_schema_example() {
        return array(
            'format_version' => '2.0',
            'package_name'   => 'Example plugin patch',
            'description'    => 'Small reviewed patch for one installed plugin.',
            'target'         => array(
                'plugin' => 'example-plugin/example-plugin.php',
            ),
            'operations'     => array(
                array(
                    'type'    => 'replace_once',
                    'file'    => 'includes/class-example-plugin.php',
                    'find'    => "return 'old value';",
                    'replace' => "return 'new value';",
                ),
                array(
                    'type'    => 'insert_after_once',
                    'file'    => 'example-plugin.php',
                    'anchor'  => "add_action( 'init', 'example_boot' );",
                    'content' => "\nadd_action( 'admin_notices', 'example_notice' );\n",
                ),
                array(
                    'type' => 'remove_once',
                    'file' => 'includes/legacy.php',
                    'find' => "add_action( 'wp_footer', 'example_old_debug_output' );\n",
                ),
            ),
        );
    }

    private function validate_package_shape( $package ) {
        $errors = array();
        if ( empty( $package['format_version'] ) ) {
            $errors[] = __( 'format_version ontbreekt in het patchpakket.', 'ai-patch-runner' );
        }
        if ( empty( $package['package_name'] ) ) {
            $errors[] = __( 'package_name ontbreekt in het patchpakket.', 'ai-patch-runner' );
        }
        if ( empty( $package['target']['plugin'] ) ) {
            $errors[] = __( 'target.plugin ontbreekt in het patchpakket.', 'ai-patch-runner' );
        }
        if ( empty( $package['operations'] ) || ! is_array( $package['operations'] ) ) {
            $errors[] = __( 'Het patchpakket moet een ‘operations’-array bevatten.', 'ai-patch-runner' );
            return $errors;
        }
        $allowed = array( 'replace_once', 'insert_before_once', 'insert_after_once', 'remove_once', 'write_file_if_missing' );
        foreach ( $package['operations'] as $index => $operation ) {
            if ( ! is_array( $operation ) ) {
                $errors[] = sprintf( __( 'Operatie %d is geen geldig object.', 'ai-patch-runner' ), $index + 1 );
                continue;
            }
            if ( empty( $operation['type'] ) || ! in_array( $operation['type'], $allowed, true ) ) {
                $errors[] = sprintf( __( 'Operatie %d heeft een niet-ondersteund type.', 'ai-patch-runner' ), $index + 1 );
            }
            if ( empty( $operation['file'] ) || ! is_string( $operation['file'] ) ) {
                $errors[] = sprintf( __( 'Operatie %d mist een bestandspad.', 'ai-patch-runner' ), $index + 1 );
            }
        }
        return $errors;
    }

    private function build_preview_checks( $selected_plugin, $package ) {
        $checks = array();
        $plugin_base = $this->get_plugin_base_dir( $selected_plugin );
        $file_changes = array();

        if ( ! empty( $package['target']['plugin'] ) && $package['target']['plugin'] !== $selected_plugin ) {
            $checks[] = array(
                'type' => 'target_mismatch',
                'file' => 'package',
                'ok' => false,
                'message' => __( 'De opgegeven doelplugin komt niet overeen met je selectie.', 'ai-patch-runner' ),
                'summary' => __( 'Kies dezelfde plugin als die in het patchpakket is opgegeven.', 'ai-patch-runner' ),
                'diff_html' => '',
            );
        }

        foreach ( $package['operations'] as $index => $operation ) {
            $type = $operation['type'];
            $file_rel = $this->sanitize_relative_path( $operation['file'] ?? '' );
            $file_abs = $file_rel ? $plugin_base . '/' . $file_rel : '';
            $check = array(
                'type' => $type,
                'file' => $file_rel ? $file_rel : ( $operation['file'] ?? 'unknown' ),
                'ok' => false,
                'message' => '',
                'summary' => '',
                'diff_html' => '',
                'lint_message' => '',
            );

            if ( ! $file_rel || ! $this->is_safe_path_in_dir( $file_abs, $plugin_base, 'write_file_if_missing' === $type ) ) {
                $check['message'] = __( 'Onveilig of ongeldig bestandspad.', 'ai-patch-runner' );
                $check['summary'] = __( 'Dit pad valt buiten de geselecteerde plugin of is ongeldig.', 'ai-patch-runner' );
                $checks[] = $check;
                continue;
            }

            $base_contents = isset( $file_changes[ $file_rel ] ) ? $file_changes[ $file_rel ] : null;
            if ( null === $base_contents ) {
                if ( file_exists( $file_abs ) ) {
                    $base_contents = file_get_contents( $file_abs );
                    if ( false === $base_contents ) {
                        $check['message'] = __( 'Doelbestand kon niet worden gelezen.', 'ai-patch-runner' );
                        $check['summary'] = __( 'Het doelbestand bestaat, maar kon niet worden gelezen.', 'ai-patch-runner' );
                        $checks[] = $check;
                        continue;
                    }
                } else {
                    $base_contents = null;
                }
            }

            if ( 'write_file_if_missing' === $type ) {
                if ( null !== $base_contents ) {
                    $check['message'] = __( 'Doelbestand bestaat al.', 'ai-patch-runner' );
                    $check['summary'] = __( 'Deze operatie maakt alleen een bestand aan als het nog niet bestaat.', 'ai-patch-runner' );
                    $checks[] = $check;
                    continue;
                }
                $new_contents = (string) ( $operation['content'] ?? '' );
                $check['ok'] = true;
                $check['message'] = __( 'Nieuw bestand wordt aangemaakt.', 'ai-patch-runner' );
                $check['summary'] = __( 'Maakt één nieuw bestand aan binnen de geselecteerde plugin.', 'ai-patch-runner' );
                $check['diff_html'] = $this->build_line_diff_html( '', $new_contents );
                $file_changes[ $file_rel ] = $new_contents;
                if ( $this->is_php_file( $file_rel ) ) {
                    $lint = $this->lint_php_string( $new_contents );
                    $check['ok'] = $check['ok'] && $lint['ok'];
                    $check['lint_message'] = $lint['message'];
                    if ( ! $lint['ok'] ) {
                        $check['message'] = __( 'Nieuw bestand is niet door de PHP lint-simulatie gekomen.', 'ai-patch-runner' );
                    }
                }
                $checks[] = $check;
                continue;
            }

            if ( null === $base_contents ) {
                $check['message'] = __( 'Doelbestand bestaat niet.', 'ai-patch-runner' );
                $check['summary'] = __( 'Het doelbestand is niet gevonden in de geselecteerde plugin.', 'ai-patch-runner' );
                $checks[] = $check;
                continue;
            }

            $new_contents = $base_contents;
            if ( 'replace_once' === $type || 'remove_once' === $type ) {
                $needle = (string) ( $operation['find'] ?? '' );
                $matches = '' === $needle ? 0 : substr_count( $base_contents, $needle );
                $check['ok'] = 1 === $matches;
                $check['message'] = 1 === $matches ? __( 'Exact zoekblok één keer gevonden.', 'ai-patch-runner' ) : sprintf( __( 'Verwachtte het exacte zoekblok 1×, maar vond het %d×.', 'ai-patch-runner' ), $matches );
                $check['summary'] = 'remove_once' === $type ? __( 'Verwijdert precies één gematcht codeblok.', 'ai-patch-runner' ) : __( 'Vervangt precies één gematcht codeblok.', 'ai-patch-runner' );
                $replace = 'replace_once' === $type ? (string) ( $operation['replace'] ?? '' ) : '';
                $check['diff_html'] = $this->build_line_diff_html( $needle, $replace );
                if ( $check['ok'] ) {
                    $new_contents = str_replace( $needle, $replace, $base_contents );
                }
            } else {
                $anchor = (string) ( $operation['anchor'] ?? '' );
                $content = (string) ( $operation['content'] ?? '' );
                $matches = '' === $anchor ? 0 : substr_count( $base_contents, $anchor );
                $check['ok'] = 1 === $matches;
                $check['message'] = 1 === $matches ? __( 'Exact anker één keer gevonden.', 'ai-patch-runner' ) : sprintf( __( 'Verwachtte het exacte anker 1×, maar vond het %d×.', 'ai-patch-runner' ), $matches );
                $check['summary'] = 'insert_before_once' === $type ? __( 'Voegt nieuwe code direct vóór één anker in.', 'ai-patch-runner' ) : __( 'Voegt nieuwe code direct na één anker in.', 'ai-patch-runner' );
                $check['diff_html'] = $this->build_insert_diff_html( $anchor, $content, 'insert_before_once' === $type );
                if ( $check['ok'] ) {
                    $new_contents = 'insert_before_once' === $type ? str_replace( $anchor, $content . $anchor, $base_contents ) : str_replace( $anchor, $anchor . $content, $base_contents );
                }
            }

            if ( $check['ok'] && $this->is_php_file( $file_rel ) ) {
                $lint = $this->lint_php_string( $new_contents );
                $check['ok'] = $check['ok'] && $lint['ok'];
                $check['lint_message'] = $lint['message'];
                if ( ! $lint['ok'] ) {
                    $check['message'] = __( 'Gewijzigd PHP-bestand is niet door de lint-controle gekomen.', 'ai-patch-runner' );
                }
            }

            if ( $check['ok'] ) {
                $file_changes[ $file_rel ] = $new_contents;
            }
            $checks[] = $check;
        }

        return $checks;
    }

    private function group_checks_by_file( $checks ) {
        $grouped = array();
        foreach ( $checks as $check ) {
            $grouped[ $check['file'] ][] = $check;
        }
        return $grouped;
    }

    private function calculate_risk( $package, $checks ) {
        $score      = 0;
        $files      = array();
        $operations = isset( $package['operations'] ) && is_array( $package['operations'] ) ? $package['operations'] : array();

        foreach ( $operations as $operation ) {
            $type = $operation['type'] ?? '';
            $file = $operation['file'] ?? '';
            $files[ $file ] = true;
            if ( 'remove_once' === $type ) {
                $score += 3;
            } elseif ( 'write_file_if_missing' === $type ) {
                $score += 2;
            } else {
                $score += 1;
            }
            if ( basename( $file ) === $file || '.php' === substr( $file, -4 ) && ! str_contains( $file, '/' ) ) {
                $score += 2;
            }
        }
        if ( count( $files ) > 2 ) {
            $score += 2;
        }
        foreach ( $checks as $check ) {
            if ( empty( $check['ok'] ) ) {
                $score += 3;
            }
        }
        $label = __( 'Low', 'ai-patch-runner' );
        if ( $score >= 8 ) {
            $label = __( 'High', 'ai-patch-runner' );
        } elseif ( $score >= 4 ) {
            $label = __( 'Medium', 'ai-patch-runner' );
        }
        return array( 'score' => $score, 'label' => $label );
    }

    private function render_diff_preview( $check ) {
        return ! empty( $check['diff_html'] ) ? '<div class="aipr-diff-view">' . $check['diff_html'] . '</div>' : '';
    }

    private function build_line_diff_html( $before, $after ) {
        $before_lines = preg_split( '/\R/', (string) $before );
        $after_lines = preg_split( '/\R/', (string) $after );
        $html = '<div class="aipr-diff-block">';
        foreach ( $before_lines as $line ) {
            if ( '' === $line && '' === trim( (string) $before ) ) { continue; }
            $html .= '<div class="aipr-diff-line is-remove"><span>-</span><code>' . esc_html( $line ) . '</code></div>';
        }
        foreach ( $after_lines as $line ) {
            if ( '' === $line && '' === trim( (string) $after ) ) { continue; }
            $html .= '<div class="aipr-diff-line is-add"><span>+</span><code>' . esc_html( $line ) . '</code></div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function build_insert_diff_html( $anchor, $insert, $before ) {
        $html = '<div class="aipr-diff-block">';
        if ( $before ) {
            foreach ( preg_split( '/\R/', (string) $insert ) as $line ) {
                if ( '' === $line && '' === trim( (string) $insert ) ) { continue; }
                $html .= '<div class="aipr-diff-line is-add"><span>+</span><code>' . esc_html( $line ) . '</code></div>';
            }
        }
        foreach ( preg_split( '/\R/', (string) $anchor ) as $line ) {
            if ( '' === $line && '' === trim( (string) $anchor ) ) { continue; }
            $html .= '<div class="aipr-diff-line is-context"><span> </span><code>' . esc_html( $line ) . '</code></div>';
        }
        if ( ! $before ) {
            foreach ( preg_split( '/\R/', (string) $insert ) as $line ) {
                if ( '' === $line && '' === trim( (string) $insert ) ) { continue; }
                $html .= '<div class="aipr-diff-line is-add"><span>+</span><code>' . esc_html( $line ) . '</code></div>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    private function apply_package( $selected_plugin, $package ) {
        $plugin_base = $this->get_plugin_base_dir( $selected_plugin );
        $writes = 0;
        $targets = array();

        foreach ( $package['operations'] as $operation ) {
            $type = $operation['type'];
            $file_rel = $this->sanitize_relative_path( $operation['file'] ?? '' );
            $file_abs = $plugin_base . '/' . $file_rel;

            if ( ! $file_rel || ! $this->is_safe_path_in_dir( $file_abs, $plugin_base, 'write_file_if_missing' === $type ) ) {
                return new WP_Error( 'aipr_bad_path', __( 'Patch afgebroken omdat een bestandspad niet veilig is.', 'ai-patch-runner' ) );
            }

            if ( ! isset( $targets[ $file_rel ] ) ) {
                if ( file_exists( $file_abs ) ) {
                    $existing = file_get_contents( $file_abs );
                    if ( false === $existing ) {
                        return new WP_Error( 'aipr_read_failed', sprintf( __( 'Kon %s niet lezen.', 'ai-patch-runner' ), $file_rel ) );
                    }
                    $targets[ $file_rel ] = array( 'path' => $file_abs, 'exists' => true, 'contents' => $existing );
                } else {
                    $targets[ $file_rel ] = array( 'path' => $file_abs, 'exists' => false, 'contents' => '' );
                }
            }

            $current = $targets[ $file_rel ]['contents'];
            if ( 'write_file_if_missing' === $type ) {
                if ( $targets[ $file_rel ]['exists'] ) {
                    return new WP_Error( 'aipr_exists', sprintf( __( 'Patch afgebroken omdat %s al bestaat.', 'ai-patch-runner' ), $file_rel ) );
                }
                $targets[ $file_rel ]['contents'] = (string) ( $operation['content'] ?? '' );
                continue;
            }

            if ( ! $targets[ $file_rel ]['exists'] ) {
                return new WP_Error( 'aipr_missing_file', sprintf( __( 'Patch afgebroken omdat %s niet bestaat.', 'ai-patch-runner' ), $file_rel ) );
            }

            if ( 'replace_once' === $type ) {
                $find = (string) ( $operation['find'] ?? '' );
                if ( 1 !== substr_count( $current, $find ) ) {
                    return new WP_Error( 'aipr_replace_mismatch', sprintf( __( 'Het te vervangen blok in %s komt niet langer exact 1× overeen.', 'ai-patch-runner' ), $file_rel ) );
                }
                $targets[ $file_rel ]['contents'] = str_replace( $find, (string) ( $operation['replace'] ?? '' ), $current );
            } elseif ( 'remove_once' === $type ) {
                $find = (string) ( $operation['find'] ?? '' );
                if ( 1 !== substr_count( $current, $find ) ) {
                    return new WP_Error( 'aipr_remove_mismatch', sprintf( __( 'Het te verwijderen blok in %s komt niet langer exact 1× overeen.', 'ai-patch-runner' ), $file_rel ) );
                }
                $targets[ $file_rel ]['contents'] = str_replace( $find, '', $current );
            } elseif ( 'insert_before_once' === $type ) {
                $anchor = (string) ( $operation['anchor'] ?? '' );
                if ( 1 !== substr_count( $current, $anchor ) ) {
                    return new WP_Error( 'aipr_anchor_mismatch', sprintf( __( 'Het ‘invoegen-vóór’-anker in %s komt niet langer exact 1× overeen.', 'ai-patch-runner' ), $file_rel ) );
                }
                $targets[ $file_rel ]['contents'] = str_replace( $anchor, (string) ( $operation['content'] ?? '' ) . $anchor, $current );
            } else {
                $anchor = (string) ( $operation['anchor'] ?? '' );
                if ( 1 !== substr_count( $current, $anchor ) ) {
                    return new WP_Error( 'aipr_anchor_mismatch', sprintf( __( 'Het ‘invoegen-na’-anker in %s komt niet langer exact 1× overeen.', 'ai-patch-runner' ), $file_rel ) );
                }
                $targets[ $file_rel ]['contents'] = str_replace( $anchor, $anchor . (string) ( $operation['content'] ?? '' ), $current );
            }
        }

        foreach ( $targets as $file_rel => $target ) {
            if ( $this->is_php_file( $file_rel ) ) {
                $lint = $this->lint_php_string( $target['contents'] );
                if ( ! $lint['ok'] ) {
                    return new WP_Error( 'aipr_lint_failed', sprintf( __( 'Patch afgebroken omdat %1$s niet door de PHP lint-simulatie kwam: %2$s', 'ai-patch-runner' ), $file_rel, $lint['message'] ) );
                }
            }
        }

        foreach ( $targets as $file_rel => $target ) {
            if ( $target['exists'] ) {
                $backup = $this->backup_file( $selected_plugin, $target['path'] );
                if ( is_wp_error( $backup ) ) {
                    return $backup;
                }
            }
            $write = $this->write_file_raw( $target['path'], $target['contents'] );
            if ( is_wp_error( $write ) ) {
                return $write;
            }
            $writes++;
        }

        return array( 'writes' => $writes );
    }

    private function write_file_raw( $file_abs, $contents ) {
        $dir = dirname( $file_abs );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'aipr_mkdir_failed', sprintf( __( 'Kon de map voor %s niet aanmaken.', 'ai-patch-runner' ), basename( $file_abs ) ) );
        }

        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( WP_Filesystem() && is_object( $wp_filesystem ) ) {
            if ( ! $wp_filesystem->is_dir( $dir ) ) {
                $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR );
            }
            if ( $wp_filesystem->put_contents( $file_abs, $contents, FS_CHMOD_FILE ) ) {
                return true;
            }
        }

        return new WP_Error( 'aipr_write_failed', sprintf( __( 'Kon %s niet veilig wegschrijven via WP_Filesystem.', 'ai-patch-runner' ), basename( $file_abs ) ) );
    }

    private function backup_file( $selected_plugin, $file_abs ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'aipr_uploads_error', $uploads['error'] );
        }
        $backup_dir = trailingslashit( $uploads['basedir'] ) . 'aipr-backups/' . sanitize_title_with_dashes( dirname( $selected_plugin ) ) . '/' . gmdate( 'Ymd-His' );
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'aipr_backup_dir', __( 'Kon de back-upmap niet aanmaken.', 'ai-patch-runner' ) );
        }
        $this->harden_backup_directory( $backup_dir );
        $target_name = md5( $file_abs ) . '-' . basename( $file_abs );
        $target = trailingslashit( $backup_dir ) . $target_name;
        if ( ! $this->is_safe_backup_path( $target ) ) {
            return new WP_Error( 'aipr_backup_path', __( 'Het back-uppad is niet veilig.', 'ai-patch-runner' ) );
        }
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
            return new WP_Error( 'aipr_backup_failed', __( 'Kon WordPress Filesystem niet initialiseren voor de back-up.', 'ai-patch-runner' ) );
        }

        $source_contents = $wp_filesystem->get_contents( $file_abs );
        if ( false === $source_contents || ! $wp_filesystem->put_contents( $target, $source_contents, FS_CHMOD_FILE ) ) {
            return new WP_Error( 'aipr_backup_failed', sprintf( __( 'Kon geen back-up maken voor %s.', 'ai-patch-runner' ), basename( $file_abs ) ) );
        }
        $this->push_backup_entry(
            array(
                'id'          => wp_generate_uuid4(),
                'plugin'      => $selected_plugin,
                'file'        => $file_abs,
                'source_file' => wp_normalize_path( str_replace( trailingslashit( WP_PLUGIN_DIR ), '', $file_abs ) ),
                'backup_path' => $target,
                'date'        => wp_date( 'Y-m-d H:i' ),
            )
        );
        return true;
    }

    private function get_backup_base_dir() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return '';
        }
        return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'aipr-backups/';
    }

    private function is_safe_backup_path( $path ) {
        $base = $this->get_backup_base_dir();
        if ( '' === $base || '' === (string) $path ) {
            return false;
        }
        $normalized = wp_normalize_path( (string) $path );
        if ( false !== strpos( $normalized, '../' ) ) {
            return false;
        }
        return str_starts_with( $normalized, $base );
    }

    private function harden_backup_directory( $backup_dir ) {
        $base_dir = $this->get_backup_base_dir();
        $dirs = array_filter(
            array_unique(
                array(
                    $base_dir,
                    dirname( wp_normalize_path( $backup_dir ) ),
                    wp_normalize_path( $backup_dir ),
                )
            )
        );

        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
            return;
        }

        foreach ( $dirs as $dir ) {
            if ( '' === $base_dir || ! str_starts_with( wp_normalize_path( $dir ), $base_dir ) ) {
                continue;
            }

            $files = array(
                trailingslashit( $dir ) . 'index.php' => "<?php\n// Silence is golden.\n",
                trailingslashit( $dir ) . '.htaccess' => "Options -Indexes\n<Files *>\n\tRequire all denied\n</Files>\n",
            );

            foreach ( $files as $file => $contents ) {
                if ( ! $wp_filesystem->exists( $file ) ) {
                    $wp_filesystem->put_contents( $file, $contents, FS_CHMOD_FILE );
                }
            }
        }
    }

    private function inspect_plugin( $plugin_file ) {
        if ( ! function_exists( 'get_plugin_files' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $files           = get_plugin_files( $plugin_file );
        $hooks           = 0;
        $functions       = 0;
        $classes         = 0;
        $hook_names      = array();
        $review_patterns = array();
        $suggested_files = array();
        $php_files       = 0;

        foreach ( $files as $relative ) {
            if ( '.php' !== substr( $relative, -4 ) ) {
                continue;
            }

            $php_files++;
            $abs      = WP_PLUGIN_DIR . '/' . $relative;
            $contents = file_exists( $abs ) ? file_get_contents( $abs ) : false;

            if ( false === $contents ) {
                continue;
            }

            if ( preg_match_all( '/\b(add_action|add_filter|do_action|apply_filters)\s*\(/', $contents, $matches ) ) {
                $hooks += count( $matches[0] );
            }

            if ( preg_match_all( '/\b(add_action|add_filter)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches ) ) {
                foreach ( $matches[2] as $hook_name ) {
                    $hook_names[] = $hook_name;
                }
            }

            if ( preg_match_all( '/\bfunction\s+[a-zA-Z0-9_]+\s*\(/', $contents, $matches ) ) {
                $functions += count( $matches[0] );
            }

            if ( preg_match_all( '/\bclass\s+[a-zA-Z0-9_]+\b/', $contents, $matches ) ) {
                $classes += count( $matches[0] );
            }

            $review_patterns = array_merge( $review_patterns, $this->scan_review_worthy_patterns( $contents, $relative ) );

            if ( false !== strpos( $relative, 'includes/' ) || basename( $relative ) === basename( $plugin_file ) ) {
                $suggested_files[] = $relative;
            }
        }

        $hook_names      = array_values( array_unique( $hook_names ) );
        $review_patterns = array_values( array_unique( $review_patterns ) );
        $suggested_files = array_slice( array_values( array_unique( $suggested_files ) ), 0, 30 );

        sort( $hook_names );
        sort( $review_patterns );

        return array(
            'php_files'       => $php_files,
            'hooks'           => $hooks,
            'functions'       => $functions,
            'classes'         => $classes,
            'hook_names'      => $hook_names,
            'review_patterns' => $review_patterns,
            'suggested_files' => $suggested_files,
        );
    }

    private function scan_review_worthy_patterns( $contents, $relative ) {
        $needles = array(
            'eval',
            'base64_decode',
            'shell_exec',
            'exec',
            'system',
            'passthru',
            'proc_open',
            'popen',
            'curl_exec',
            'wp_remote_get',
            'wp_remote_post',
        );

        $found  = array();
        $tokens = token_get_all( $contents );

        foreach ( $tokens as $index => $token ) {
            if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
                continue;
            }

            if ( ! in_array( $token[1], $needles, true ) ) {
                continue;
            }

            $next_index = $index + 1;
            while ( isset( $tokens[ $next_index ] ) && is_array( $tokens[ $next_index ] ) && T_WHITESPACE === $tokens[ $next_index ][0] ) {
                $next_index++;
            }

            if ( isset( $tokens[ $next_index ] ) && '(' === $tokens[ $next_index ] ) {
                $found[] = $token[1] . '() → ' . $relative;
            }
        }

        return $found;
    }

    private function get_plugin_base_dir( $plugin_file ) {
        $dir_part = dirname( $plugin_file );
        return '.' === $dir_part ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . trim( $dir_part, '/' );
    }

    private function sanitize_relative_path( $path ) {
        $path = wp_normalize_path( (string) $path );
        $path = ltrim( $path, '/' );
        if ( '' === $path || str_contains( $path, '../' ) || str_contains( $path, '..\\' ) ) {
            return '';
        }
        return $path;
    }

    private function is_safe_path_in_dir( $file_abs, $base_dir, $allow_missing ) {
        $base_real = realpath( $base_dir );
        if ( false === $base_real ) {
            return false;
        }

        $base_path = trailingslashit( wp_normalize_path( $base_real ) );

        if ( $allow_missing ) {
            $dir_real = realpath( dirname( $file_abs ) );
            if ( false === $dir_real ) {
                return false;
            }

            $dir_path = trailingslashit( wp_normalize_path( $dir_real ) );
            return $base_path === $dir_path || str_starts_with( $dir_path, $base_path );
        }

        $file_real = realpath( $file_abs );
        if ( false === $file_real ) {
            return false;
        }

        $file_path = wp_normalize_path( $file_real );
        return $base_path === trailingslashit( $file_path ) || str_starts_with( $file_path, $base_path );
    }

    private function is_php_file( $path ) {
        return '.php' === strtolower( substr( (string) $path, -4 ) );
    }

    private function lint_php_string( $php ) {
        if ( ! $this->is_php_code( $php ) ) {
            return array( 'ok' => true, 'message' => __( 'Geen PHP lint nodig voor dit bestand.', 'ai-patch-runner' ) );
        }
        try {
            token_get_all( $php, TOKEN_PARSE );
            return array( 'ok' => true, 'message' => __( 'PHP lint-simulatie geslaagd.', 'ai-patch-runner' ) );
        } catch ( ParseError $e ) {
            return array( 'ok' => false, 'message' => $e->getMessage() );
        }
    }

    private function is_php_code( $contents ) {
        return str_contains( (string) $contents, '<?php' );
    }

    private function get_operation_label( $type ) {
        $labels = array(
            'replace_once' => __( 'Blok vervangen', 'ai-patch-runner' ),
            'insert_before_once' => __( 'Invoegen vóór', 'ai-patch-runner' ),
            'insert_after_once' => __( 'Invoegen na', 'ai-patch-runner' ),
            'write_file_if_missing' => __( 'Nieuw bestand aanmaken', 'ai-patch-runner' ),
            'remove_once' => __( 'Blok verwijderen', 'ai-patch-runner' ),
            'target_mismatch' => __( 'Target mismatch', 'ai-patch-runner' ),
            'package' => __( 'Pakketvalidatie', 'ai-patch-runner' ),
        );
        return $labels[ $type ] ?? $type;
    }

    private function redirect_notice( $type, $message, $page = 'ai-patch-runner' ) {
        $url = add_query_arg(
            array(
                'page' => $page,
                'aipr_notice' => $type,
                'aipr_message' => $message,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }
}

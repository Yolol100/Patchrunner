<?php
/**
 * Plugin Name: AI Patch Runner
 * Description: Import reviewed patch packages, preview exact plugin file changes, inspect plugins, manage backups and apply patches safely inside WordPress.
 * Version: 3.1.7
 * Author: OpenAI
 * Text Domain: ai-patch-runner
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIPR_VERSION', '3.1.7' );
define( 'AIPR_FILE', __FILE__ );
define( 'AIPR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPR_URL', plugin_dir_url( __FILE__ ) );

require_once AIPR_DIR . 'includes/class-aipr-admin.php';
require_once AIPR_DIR . 'includes/class-aipr-plugin.php';

function aipr_boot() {
    if ( ! class_exists( 'AIPR_Plugin' ) ) {
        return;
    }

    $plugin = new AIPR_Plugin();
    $plugin->boot();
}

if ( class_exists( 'AIPR_Plugin' ) ) {
    register_activation_hook( AIPR_FILE, array( 'AIPR_Plugin', 'activate' ) );
    register_deactivation_hook( AIPR_FILE, array( 'AIPR_Plugin', 'deactivate' ) );
}

aipr_boot();

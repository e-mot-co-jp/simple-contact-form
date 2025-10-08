<?php
if (!defined('ABSPATH')) exit;

/**
 * ML retrain schedule and handler
 * - Schedules a daily retrain at 03:30 server time if not already scheduled
 * - Executes ml/retrain_deploy.sh with optional PYTHON_BIN from settings if provided
 */

if ( ! wp_next_scheduled( 'scf_ml_retrain_event' ) ) {
    // Run daily at 03:30. If time() is already past today 03:30, WP will schedule for next occurrence automatically.
    wp_schedule_event( strtotime('03:30:00'), 'daily', 'scf_ml_retrain_event' );
}

add_action( 'scf_ml_retrain_event', function() {
    // Base dir relative to this plugin
    $plugin_dir = plugin_dir_path( dirname(__FILE__) );
    $ml_dir = trailingslashit( $plugin_dir . 'ml' );

    // Allow overriding python path via option set in settings page
    $python = trim( get_option('scf_python_path', '') );
    if ($python === '') {
        // Fallback path (previous environment); safe to leave blank if not applicable
        $python = '/home/xs683807/miniconda3/bin/python';
    }

    // Build command and execute in background, suppressing output
    $cmd = "cd " . escapeshellarg($ml_dir) . " && PYTHON_BIN=" . escapeshellarg($python) . " ./retrain_deploy.sh";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
    exec($cmd . ' > /dev/null 2>&1 &');
});

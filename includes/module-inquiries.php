<?php
if (!defined('ABSPATH')) exit;

/**
 * お問い合わせテーブル作成・アップグレード
 */
if (!function_exists('scf_create_table')) {
function scf_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") != $table ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_no VARCHAR(32),
            name VARCHAR(255),
            email VARCHAR(255),
            zip VARCHAR(32),
            address TEXT,
            tel VARCHAR(64),
            inquiry VARCHAR(255),
            product VARCHAR(255),
            date VARCHAR(32),
            shop VARCHAR(255),
            content TEXT,
            files TEXT,
            is_spam TINYINT(1) DEFAULT 0,
            spam_note TEXT,
            spam_engine VARCHAR(64),
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
}

if (!function_exists('scf_upgrade_table_schema')) {
function scf_upgrade_table_schema(){
    global $wpdb; $table = $wpdb->prefix.'scf_inquiries';
    if( $wpdb->get_var("SHOW TABLES LIKE '$table'") != $table ) return;
    $cols = $wpdb->get_results("DESCRIBE $table");
    if( ! $cols ) return;
    $have = [];
    foreach($cols as $c){ $have[$c->Field] = true; }
    $alters = [];
    if( empty($have['files']) )      $alters[] = 'ADD files TEXT AFTER content';
    if( empty($have['is_spam']) )     $alters[] = 'ADD is_spam TINYINT(1) DEFAULT 0 AFTER files';
    if( empty($have['spam_note']) )   $alters[] = 'ADD spam_note TEXT AFTER is_spam';
    if( empty($have['spam_engine']) ) $alters[] = 'ADD spam_engine VARCHAR(64) AFTER spam_note';
    if( empty($have['created']) )     $alters[] = 'ADD created DATETIME DEFAULT CURRENT_TIMESTAMP AFTER spam_engine';
    if( $alters ){
        $sql = 'ALTER TABLE '.$table.' '.implode(', ', $alters);
        $wpdb->query($sql);
        if( defined('WP_DEBUG') && WP_DEBUG ) error_log('[scf] schema upgraded: '.$sql.' error='.$wpdb->last_error);
    }
}
}

if (!function_exists('scf_ensure_inquiries_table_schema')) {
function scf_ensure_inquiries_table_schema(){
    if ( defined('WP_INSTALLING') && WP_INSTALLING ) return;
    scf_create_table();
    scf_upgrade_table_schema();
}
}

// 二重登録防止のため、既に同一コールバックが登録されていない場合のみ追加
if ( ! has_action('init', 'scf_ensure_inquiries_table_schema') ) {
    add_action('init','scf_ensure_inquiries_table_schema',1);
}

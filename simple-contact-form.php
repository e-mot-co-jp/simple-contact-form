<?php
/*
Plugin Name: Simple Contact Form
Plugin URI: https://e-mot.co.jp/
Description: シンプルなお問い合わせフォーム（ファイル添付・郵便番号→住所自動入力(yubinbango)対応）
Version: 1.2.0
Author: Yu Ishiga
Author URI: https://backcountry-works.com/
Text Domain: simple-contact-form
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Update URI: https://e-mot.co.jp/
*/

if (!defined('ABSPATH')) exit;
// モジュール読み込み
require_once plugin_dir_path(__FILE__) . 'includes/module-spam.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-inquiries.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-inquiries.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-ml.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-assets.php';
require_once plugin_dir_path(__FILE__) . 'includes/module-frontend.php';

// お問い合わせデータ保存用テーブル作成
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
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
});

// お問い合わせ管理メニュー追加
add_action('admin_menu', function() {
    add_menu_page(
        'お問い合わせ管理',
        'お問い合わせ管理',
        'manage_scf',
        'scf_inquiry_list',
        'scf_admin_inquiry_list_page',
        'dashicons-email-alt2',
        26
    );
    add_submenu_page(
        'scf_inquiry_list',
        'お問い合わせ設定',
        '設定',
        'manage_scf',
        'scf_inquiry_settings',
        'scf_admin_inquiry_settings_page'
    );
    // 詳細ページ（一覧からの遷移先）
    add_submenu_page(
        'scf_inquiry_list',
        'お問い合わせ詳細',
        'お問い合わせ詳細',
        'manage_scf',
        'scf_inquiry_view',
        'scf_admin_inquiry_view_page'
    );
    // spam_list管理サブメニュー
    add_submenu_page(
        'scf_inquiry_list',
        'spam_list管理',
        'spam_list管理',
        'manage_scf',
        'scf_spam_list',
        function() {
            require_once plugin_dir_path(__FILE__) . 'includes/admin-spam-list.php';
            scf_admin_spam_list_page();
        }
    );
});

// 任意のロールにアクセス権を付与（administrator, repair_service, shop_manager）
add_action('init', function() {
    $cap = 'manage_scf';
    $roles = ['administrator', 'repair_service', 'shop_manager'];
    foreach ($roles as $rname) {
        $role = get_role($rname);
        if ($role && ! $role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
});

// ファイル保持期間に応じて添付ファイルを自動削除
add_action('scf_delete_old_attachments', function() {
    $period = intval(get_option('scf_file_period', 365));
    $before = $period > 0 ? $period . ' days ago' : '365 days ago';
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'date_query'     => [ [ 'before' => $before, 'column' => 'post_date_gmt' ] ],
        'meta_query'     => [ [ 'key' => '_scf_uploaded', 'value' => '1' ] ],
        'fields'         => 'ids',
    ];
    $attachments = get_posts($args);
    foreach ($attachments as $att_id) {
        wp_delete_attachment($att_id, true);
    }
});
if (!wp_next_scheduled('scf_delete_old_attachments')) {
    wp_schedule_event(time(), 'daily', 'scf_delete_old_attachments');
}
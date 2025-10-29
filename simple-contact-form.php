<?php
/*
Plugin Name: Simple Contact Form
Plugin URI: https://e-mot.co.jp/
Description: シンプルなお問い合わせフォーム（ファイル添付・郵便番号→住所自動入力(yubinbango)対応）
Version: 1.2.1
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

// repair_service / shop_manager に付与する専用 capability を確実に作成（存在する場合は何もしない）
add_action('init', function() {
    $cap = 'manage_scf';
    $roles = ['administrator', 'repair_service', 'shop_manager'];
    foreach ($roles as $r) {
        $role = get_role($r);
        if ($role && ! $role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
});

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

// マイアカウント メニュー項目追加
function scf_wc_social_connections_menu_items($items){
    if (!is_user_logged_in()) return $items;
    // dashboard の直後に挿入
    $new = [];
    foreach ($items as $k=>$v){
        $new[$k]=$v;
        if ($k==='dashboard') {
            $new['social-connections'] = __('ソーシャル連携', 'simple-contact-form');
        }
    }
    return $new;
}
add_filter('woocommerce_account_menu_items','scf_wc_social_connections_menu_items');

// C: コンテンツ描画
function scf_wc_account_social_connections_endpoint(){
    if ( ! is_user_logged_in() ) return;
    echo '<h2>'.esc_html__('ソーシャルアカウント連携','simple-contact-form').'</h2>';
    echo '<p>'.esc_html__('SNSアカウントを連携すると次回以降ワンクリックでログインできます。','simple-contact-form').'</p>';
    $user = wp_get_current_user();
    $has_password = ! empty($user->user_pass);
    if (shortcode_exists('nextend_social_login')) {
        $unlink_flag = $has_password ? '1' : '0';
        $heading = esc_attr__('Connect Social Accounts', 'simple-contact-form');
        // ログイン済みなので login ボタンは不要 -> login="0"
        $primary_sc = '[nextend_social_login login="0" link="1" unlink="'.$unlink_flag.'" heading="'.$heading.'"]';
        $html = do_shortcode($primary_sc);
        $len = strlen(trim(strip_tags($html)));
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SCF SocialLink] shortcode primary stripped_length='.$len.' unlink_flag='.$unlink_flag);
        }
        if ($len === 0) {
            // フォールバック: login="1" (一部設定で login=0 が何も返さない場合)
            $fallback_sc = '[nextend_social_login login="1" link="1" unlink="'.$unlink_flag.'" heading="'.$heading.'"]';
            $fb_html = do_shortcode($fallback_sc);
            $fb_len = strlen(trim(strip_tags($fb_html)));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SCF SocialLink] shortcode fallback stripped_length='.$fb_len);
            }
            if ($fb_len > 0) {
                echo '<div class="scf-social-connect-block">'.$fb_html.'</div>';
            } else {
                // 診断表示
                echo '<div class="scf-social-connect-block scf-nextend-empty" style="border:1px dashed #ccc;padding:16px;">';
                echo '<p style="margin:0 0 8px;color:#666;font-size:13px;">Nextend ショートコード (login/link/unlink) の出力が空です。設定を確認してください。</p>';
                $linked = [];
                $um = get_user_meta($user->ID);
                foreach($um as $k=>$vals){ if (strpos($k,'_nsl_provider_') === 0) { $linked[] = esc_html(substr($k, strlen('_nsl_provider_'))); } }
                echo '<p style="margin:0 0 4px;font-size:12px;color:#333;">接続済みプロバイダ: '.($linked?implode(', ',$linked):'(なし)').'</p>';
                echo '<ul style="margin:8px 0 0;padding-left:18px;font-size:11px;line-height:1.5;color:#555;">';
                echo '<li>少なくとも1つのプロバイダが有効 (アプリキー設定済) か</li>';
                echo '<li>ログイン済ユーザー向けの Link ボタン抑制設定が無効化されているか</li>';
                echo '<li>キャッシュ/最適化 (遅延読み込み) を一時停止</li>';
                echo '<li>必要なら provider 限定例: [nextend_social_login provider="google" login="0" link="1" unlink="'.$unlink_flag.'" heading="'.$heading.'"]</li>';
                echo '</ul>';
                echo '</div>';
            }
        } else {
            echo '<div class="scf-social-connect-block">'.$html.'</div>';
        }
    } else {
        echo '<p style="color:#666;">'.esc_html__('ソーシャルログインプラグインが有効ではありません。','simple-contact-form').'</p>';
    }
    // B: パスワード未設定なら Disconnect ボタンを JS/CSS で抑制
    if (!$has_password) {
        // unlink=0 でボタンは出ていない想定だが念のため CSS でも隠す
        echo '<style>.scf-social-connect-block .nsl-button-disconnect{display:none!important;}</style>';
        echo '<p style="margin-top:16px;color:#d33;font-size:12px;">'.esc_html__('現在パスワード未設定のため、既存連携の解除 (Unlink) は無効化されています。','simple-contact-form').'</p>';
    }
    do_action('scf_after_social_connections_block', $user);
}
add_action('woocommerce_account_social-connections_endpoint','scf_wc_account_social_connections_endpoint');

// A: ダッシュボードにも簡易表示（任意。重複が嫌ならコメントアウト可）
function scf_wc_account_dashboard_social_snippet(){
    if ( ! is_user_logged_in() ) return;
    if ( ! shortcode_exists('nextend_social_login') ) return;
    echo '<section class="scf-social-connect-mini" style="margin:24px 0;padding:16px;border:1px solid #e2e2e2;border-radius:8px;">';
    echo '<h3 style="margin:0 0 10px;font-size:16px;">'.esc_html__('ソーシャル連携','simple-contact-form').'</h3>';
    $user = wp_get_current_user();
    $has_password = ! empty($user->user_pass);
    $unlink_flag = $has_password ? '1' : '0';
    echo do_shortcode('[nextend_social_login login="0" link="1" unlink="'.$unlink_flag.'" heading="'.esc_attr__('Connect Social Accounts','simple-contact-form').'"]');
    echo '<p style="margin:8px 0 0;font-size:12px;color:#666;">'.esc_html__('詳細な管理は「ソーシャル連携」メニューへ。','simple-contact-form').'</p>';
    echo '</section>';
}
add_action('woocommerce_account_dashboard','scf_wc_account_dashboard_social_snippet', 25);

// B: Disconnect ボタン抑止をログインフォームや他箇所でも適用したい場合のヘルパー（任意拡張）
function scf_maybe_hide_disconnect_globally(){
    if ( ! is_user_logged_in() ) return;
    $user = wp_get_current_user();
    if ( empty($user->user_pass) ) {
        echo '<style>.nsl-button.nsl-button-disconnect{display:none!important;}</style>';
    }
}
add_action('wp_head','scf_maybe_hide_disconnect_globally', 120);

// 接続/解除ログ（デバッグ用途）
add_action('nsl_user_connected_provider', function($provider,$user_id,$profile){
    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[SCF SocialLink] connected provider='.$provider.' user_id='.$user_id);
}, 10, 3);
add_action('nsl_user_unlinked_provider', function($provider,$user_id){
    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[SCF SocialLink] disconnected provider='.$provider.' user_id='.$user_id);
}, 10, 2);
<?php
if (!defined('ABSPATH')) exit;

// Front-end assets enqueue (public)
if (!function_exists('scf_enqueue_scripts')) {
function scf_enqueue_scripts() {
    $plugin_main = dirname(__FILE__) . '/../simple-contact-form.php';
    // 郵便番号→住所自動入力API（yubinbango.js）
    wp_enqueue_script('yubinbango', 'https://yubinbango.github.io/yubinbango/yubinbango.js', [], null, true);
    // 独自バリデーション用（ファイル更新時刻をバージョンに使用してキャッシュ回避）
    $validate_js_path = plugin_dir_path($plugin_main) . 'validate.js';
    $validate_ver = file_exists($validate_js_path) ? filemtime($validate_js_path) : '1.0';
    wp_enqueue_script('scf-validate', plugins_url('validate.js', $plugin_main), ['jquery'], $validate_ver, true);
    // スタイルもファイル更新時刻をバージョンに使用
    $style_css_path = plugin_dir_path($plugin_main) . 'style.css';
    $style_ver = file_exists($style_css_path) ? filemtime($style_css_path) : '1.0';
    wp_enqueue_style('scf-style', plugins_url('style.css', $plugin_main), [], $style_ver);
    // Cloudflare Turnstile
    if ( get_option('scf_turnstile_enabled', 0) ) {
        wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
        // ロケットローダーから除外するためのフィルター追加
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'cf-turnstile') {
                return str_replace('<script', '<script data-cfasync="false"', $tag);
            }
            return $tag;
        }, 10, 2);
    }
    // 会員登録用パスワードリアルタイムチェック（常時読み込みでも軽量）
    $reg_js_path = plugin_dir_path($plugin_main) . 'register.js';
    $reg_ver = file_exists($reg_js_path) ? filemtime($reg_js_path) : '1.0.1';
    wp_enqueue_script('scf-register', plugins_url('register.js', $plugin_main), ['jquery'], $reg_ver, true);
    // WordPress 同梱の zxcvbn を利用（ハンドル: zxcvbn-async または zxcvbn）
    if ( ! wp_script_is('zxcvbn-async','registered') && ! wp_script_is('zxcvbn','registered') ) {
        // フォールバックCDN（理想は WP 同梱利用だが環境差異考慮）
        wp_register_script('zxcvbn', 'https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.4.2/zxcvbn.js', [], '4.4.2', true);
    }
    // 既存のコアハンドルがあればそれをenqueue、なければフォールバック
    if ( wp_script_is('zxcvbn-async','registered') ) {
        wp_enqueue_script('zxcvbn-async');
    } elseif ( wp_script_is('zxcvbn','registered') ) {
        wp_enqueue_script('zxcvbn');
    } else {
        wp_enqueue_script('zxcvbn');
    }
}
add_action('wp_enqueue_scripts', 'scf_enqueue_scripts');
}

// Contact form specific assets (file upload UI)
if (!function_exists('scf_enqueue_form_assets')) {
function scf_enqueue_form_assets() {
    $plugin_main = dirname(__FILE__) . '/../simple-contact-form.php';
    wp_enqueue_script('scf-file-upload', plugins_url('file-upload.js', $plugin_main), ['jquery'], '1.0', true);
    wp_enqueue_style('scf-file-thumb', plugins_url('file-thumb.css', $plugin_main));
}
add_action('wp_enqueue_scripts', 'scf_enqueue_form_assets');
}

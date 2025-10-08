<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('scf_admin_inquiry_settings_page')) {
function scf_admin_inquiry_settings_page() {
    if (isset($_POST['scf_settings_nonce']) && wp_verify_nonce($_POST['scf_settings_nonce'], 'scf_settings')) {
        $period = max(1, intval($_POST['scf_file_period']));
        $mail = sanitize_email($_POST['scf_support_mail']);
        $use_py = isset($_POST['scf_use_python_spam']) ? 1 : 0;
        $py_path = isset($_POST['scf_python_path']) ? sanitize_text_field($_POST['scf_python_path']) : '';
        $turnstile_enabled = isset($_POST['scf_turnstile_enabled']) ? 1 : 0;
        $turnstile_sitekey = isset($_POST['scf_turnstile_sitekey']) ? sanitize_text_field($_POST['scf_turnstile_sitekey']) : '';
        $turnstile_secret = isset($_POST['scf_turnstile_secret']) ? sanitize_text_field($_POST['scf_turnstile_secret']) : '';
        $pw_strength_min = isset($_POST['scf_pw_strength_min']) ? intval($_POST['scf_pw_strength_min']) : 0;
        if ($pw_strength_min < 0) $pw_strength_min = 0; if ($pw_strength_min > 4) $pw_strength_min = 4;
        update_option('scf_file_period', $period);
        update_option('scf_support_mail', $mail);
        update_option('scf_use_python_spam', $use_py);
        update_option('scf_python_path', $py_path);
        update_option('scf_turnstile_enabled', $turnstile_enabled);
        update_option('scf_turnstile_sitekey', $turnstile_sitekey);
        update_option('scf_turnstile_secret', $turnstile_secret);
        update_option('scf_pw_strength_min', $pw_strength_min);
        echo '<div class="updated notice"><p>設定を保存しました。</p></div>';
    }
    if (isset($_POST['scf_test_nonce']) && wp_verify_nonce($_POST['scf_test_nonce'], 'scf_test')) {
        $test_text = isset($_POST['scf_test_text']) ? sanitize_textarea_field($_POST['scf_test_text']) : '';
        list($t_flag, $t_engine, $t_note) = function_exists('scf_check_spam') ? scf_check_spam($test_text) : [false,'php','module-missing'];
        $t_label = $t_flag ? 'SPAM' : 'OK';
        echo '<div class="updated notice"><p>判定結果: <strong>' . esc_html($t_label) . '</strong>（エンジン: ' . esc_html($t_engine) . '、メモ: ' . esc_html($t_note) . '）</p></div>';
    }
    $period = get_option('scf_file_period', 365);
    $mail = get_option('scf_support_mail', 'support@e-m.co.jp');
    $use_py = get_option('scf_use_python_spam', 0);
    $py_path = get_option('scf_python_path', '~/miniconda3/bin/python');
    $turnstile_enabled = get_option('scf_turnstile_enabled', 0);
    $turnstile_sitekey = get_option('scf_turnstile_sitekey', '0x4AAAAAAB4PWT_eW58sqk4P');
    $turnstile_secret = get_option('scf_turnstile_secret', '0x4AAAAAAB4PWQ247r0OqYHTh3uHTDKkBmI');
    $pw_strength_min = get_option('scf_pw_strength_min', 0);
    echo '<div class="wrap"><h1>お問い合わせ設定</h1>';
    echo '<form method="post">';
    wp_nonce_field('scf_settings', 'scf_settings_nonce');
    echo '<table class="form-table">';
    echo '<tr><th>ファイル保持期間</th><td><input type="number" name="scf_file_period" value="'.esc_attr($period).'" min="1" style="width:80px;"> 日</td></tr>';
    echo '<tr><th>サポートメールアドレス</th><td><input type="email" name="scf_support_mail" value="'.esc_attr($mail).'" style="width:300px;"></td></tr>';
    echo '<tr><th>Pythonスパム判定を使用</th><td><label><input type="checkbox" name="scf_use_python_spam" ' . checked($use_py, 1, false) . ' /> 有効にする</label></td></tr>';
    echo '<tr><th>Python実行パス</th><td><input type="text" name="scf_python_path" value="' . esc_attr($py_path) . '" style="width:420px;" placeholder="/Users/you/miniconda3/bin/python"><p class="description">例: /Users/you/miniconda3/bin/python （~ は展開されます）</p></td></tr>';
    echo '<tr><th>Turnstile を有効化</th><td><label><input type="checkbox" name="scf_turnstile_enabled" ' . checked($turnstile_enabled, 1, false) . ' /> 有効にする</label></td></tr>';
    echo '<tr><th>Turnstile sitekey</th><td><input type="text" name="scf_turnstile_sitekey" value="' . esc_attr($turnstile_sitekey) . '" style="width:420px;"></td></tr>';
    echo '<tr><th>Turnstile secret</th><td><input type="text" name="scf_turnstile_secret" value="' . esc_attr($turnstile_secret) . '" style="width:420px;"></td></tr>';
    echo '<tr><th>最小パスワード強度</th><td><select name="scf_pw_strength_min">';
    foreach ([0=>'0 (制限なし)',1=>'1',2=>'2',3=>'3 (推奨初期値)',4=>'4 (最も厳しい)'] as $k=>$label){ echo '<option value="'.intval($k).'"'.selected($pw_strength_min,$k,false).'>'.esc_html($label).'</option>'; }
    echo '</select><p class="description">zxcvbn の score (0~4)。閾値以上で登録許可。0 は無効。</p></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="保存"></p>';
    echo '</form>';
    echo '<hr>';
    echo '<h2>スパム判定テスト</h2>';
    echo '<form method="post">';
    wp_nonce_field('scf_test', 'scf_test_nonce');
    echo '<p><textarea name="scf_test_text" rows="6" style="width:100%;" placeholder="ここにテスト用のテキスト（種別・商品名・本文などを結合したもの）を貼り付けてください"></textarea></p>';
    echo '<p><input type="submit" class="button" value="判定を実行"></p>';
    echo '</form>';
    echo '</div>';
}
}

// Admin styles (spam row highlighting etc.)
add_action('admin_enqueue_scripts', function($hook){
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if (strpos($page,'scf_inquiry_') === 0) {
        $handle = 'scf-admin';
        $file   = plugin_dir_path(dirname(__FILE__)).'admin.css';
        if ( file_exists($file) ){
            wp_enqueue_style($handle, plugins_url('admin.css', dirname(__FILE__)), [], filemtime($file));
        }
        $css = 'tr.scf-row-spam{background:#bbbbbb !important;color:#222 !important;}'
             .'tr.scf-row-spam a{color:#004f7a !important;}'
             .'tr.scf-row-spam:hover{background:#b2b2b2 !important;}'
             .'tr.scf-row-spam td,tr.scf-row-spam th{border-color:#a2a2a2 !important;}';
        wp_add_inline_style($handle, $css);
    }
});

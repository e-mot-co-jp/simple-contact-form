<?php
/*
Plugin Name: Simple Contact Form
Description: ショートコードで設置できるシンプルなお問い合わせフォーム。
Version: 1.0
Author: e-mot-co-jp
*/

if (!defined('ABSPATH')) exit;

function scf_enqueue_scripts() {
    // 郵便番号→住所自動入力API（yubinbango.js）
    wp_enqueue_script('yubinbango', 'https://yubinbango.github.io/yubinbango/yubinbango.js', [], null, true);
    // 独自バリデーション用
    wp_enqueue_script('scf-validate', plugins_url('validate.js', __FILE__), ['jquery'], '1.0', true);
    wp_enqueue_style('scf-style', plugins_url('style.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'scf_enqueue_scripts');

function scf_render_form($atts = []) {
    ob_start();
    ?>
    <form class="h-adr scf-form" method="post" action="">
        <span class="p-country-name" style="display:none;">Japan</span>
        <label>*お名前<br><input type="text" name="scf_name" required></label><br>
        <label>*メールアドレス<br><input type="email" name="scf_email" required></label><br>
        <label>*メールアドレス確認<br><input type="email" name="scf_email_confirm" required></label><br>
        <label>*郵便番号<br><input type="text" name="scf_zip" class="p-postal-code" size="8" maxlength="8" required></label><br>
        <label>*住所<br><input type="text" name="scf_address" class="p-region p-locality p-street-address p-extended-address" required></label><br>
        <label>*電話番号<br><input type="tel" name="scf_tel" required></label><br>
        <label>*お問い合わせ内容<br>
            <select name="scf_inquiry" required>
                <option value="">選択してください</option>
                <option value="保証内容について">保証内容について</option>
                <option value="オンラインショップについて">オンラインショップについて</option>
                <option value="製品の仕様などについて">製品の仕様などについて</option>
                <option value="リコールについて">リコールについて</option>
                <option value="その他">その他</option>
            </select>
        </label><br>
        <label>*商品名<br><input type="text" name="scf_product" required></label><br>
        <label>お買い上げ日<br><input type="date" name="scf_date"></label><br>
        <label>ご購入店舗名<br><input type="text" name="scf_shop"></label><br>
        <label>*内容<br><textarea name="scf_content" required></textarea></label><br>
        <button type="submit">送信</button>
    </form>
    <div class="scf-message"></div>
    <?php
    return ob_get_clean();
}
add_shortcode('simple_contact_form', 'scf_render_form');

// Ajax送信時のバリデーション・メール送信処理
add_action('init', function() {
    if (isset($_POST['scf_ajax']) && $_POST['scf_ajax'] == '1') {
        $required = ['scf_name','scf_email','scf_email_confirm','scf_zip','scf_address','scf_tel','scf_inquiry','scf_product','scf_content'];
        $errors = [];
        foreach ($required as $key) {
            if (empty($_POST[$key])) {
                $errors[] = $key . 'は必須です。';
            }
        }
        if ($_POST['scf_email'] !== $_POST['scf_email_confirm']) {
            $errors[] = 'メールアドレスが一致しません。';
        }
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode("\n", $errors)]);
        }
        // メール送信
        $to = get_option('admin_email');
        $subject = '[お問い合わせ] ' . sanitize_text_field($_POST['scf_inquiry']);
        $body = "お名前: {$_POST['scf_name']}\n".
                "メール: {$_POST['scf_email']}\n".
                "郵便番号: {$_POST['scf_zip']}\n".
                "住所: {$_POST['scf_address']}\n".
                "電話番号: {$_POST['scf_tel']}\n".
                "お問い合わせ種別: {$_POST['scf_inquiry']}\n".
                "商品名: {$_POST['scf_product']}\n".
                "お買い上げ日: {$_POST['scf_date']}\n".
                "ご購入店舗名: {$_POST['scf_shop']}\n".
                "内容: {$_POST['scf_content']}\n";
        $headers = ['From: '.get_bloginfo('name').' <'.$to.'>'];
        $sent = wp_mail($to, $subject, $body, $headers);
        if ($sent) {
            wp_send_json_success(['message' => '送信が完了しました。']);
        } else {
            wp_send_json_error(['message' => '送信に失敗しました。']);
        }
        exit;
    }
});

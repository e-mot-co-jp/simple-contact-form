<?php
/*
Plugin Name: Simple Contact Form
Description: ショートコードで設置できるシンプルなお問い合わせフォーム。
Version: 1.0
Author: Yu Ishiga
website: https://backcountry-works.com
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
    <form class="h-adr scf-form" method="post" action="" enctype="multipart/form-data">
        <span class="p-country-name" style="display:none;">Japan</span>
        <label><span class="scf-required">必須</span>お名前<br><input type="text" name="scf_name" required></label><br>
        <label><span class="scf-required">必須</span>メールアドレス<br><input type="email" name="scf_email" required></label><br>
        <label><span class="scf-required">必須</span>メールアドレス確認<br><input type="email" name="scf_email_confirm" required></label><br>
        <label><span class="scf-required">必須</span>郵便番号<br><input type="text" name="scf_zip" class="p-postal-code" size="8" maxlength="8" required></label><br>
        <label><span class="scf-required">必須</span>住所<br><input type="text" name="scf_address" class="p-region p-locality p-street-address p-extended-address" required></label><br>
        <label><span class="scf-required">必須</span>電話番号<br><input type="tel" name="scf_tel" required></label><br>
        <label><span class="scf-required">必須</span>お問い合わせ内容<br>
            <select name="scf_inquiry" required>
                <option value="">選択してください</option>
                <option value="保証内容について">保証内容について</option>
                <option value="オンラインショップについて">オンラインショップについて</option>
                <option value="製品の仕様などについて">製品の仕様などについて</option>
                <option value="リコールについて">リコールについて</option>
                <option value="その他">その他</option>
            </select>
        </label><br>
        <label><span class="scf-required">必須</span>商品名<br><input type="text" name="scf_product" required></label><br>
        <label>お買い上げ日<br><input type="date" name="scf_date"></label><br>
        <label>ご購入店舗名<br><input type="text" name="scf_shop"></label><br>
        <div class="scf-file-area">
            <label>ファイル添付（複数可・40MBまで・jpg/jpeg/gif/pdf/heic/png）</label>
            <div class="scf-dropzone" tabindex="0">ここにファイルをドラッグ＆ドロップ、またはクリックで選択</div>
            <input type="file" name="scf_files[]" class="scf-file-input" multiple accept=".jpg,.jpeg,.gif,.pdf,.heic,.png" style="display:none;">
            <div class="scf-file-list"></div>
        </div>
        <label><span class="scf-required">必須</span>内容<br><textarea name="scf_content" required></textarea></label><br>
        <button type="submit">送信</button>
    </form>
    <div class="scf-message"></div>
    <?php
    return ob_get_clean();
}
add_shortcode('simple_contact_form', 'scf_render_form');

// ファイルアップロード用JSを追加
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('scf-file-upload', plugins_url('file-upload.js', __FILE__), ['jquery'], '1.0', true);
});

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
        // ファイルバリデーション
        $uploaded_urls = [];
        if (!empty($_FILES['scf_files'])) {
            $allowed = ['jpg','jpeg','gif','pdf','heic','png'];
            $max_size = 40 * 1024 * 1024; // 40MB
            foreach ($_FILES['scf_files']['name'] as $i => $name) {
                if (!$_FILES['scf_files']['size'][$i]) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $errors[] = $name.' は許可されていないファイル形式です。';
                }
                if ($_FILES['scf_files']['size'][$i] > $max_size) {
                    $errors[] = $name.' は40MBを超えています。';
                }
            }
        }
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode("\n", $errors)]);
        }
        // ファイル保存
        if (!empty($_FILES['scf_files'])) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            require_once(ABSPATH.'wp-admin/includes/media.php');
            require_once(ABSPATH.'wp-admin/includes/image.php');
            foreach ($_FILES['scf_files']['name'] as $i => $name) {
                if (!$_FILES['scf_files']['size'][$i]) continue;
                $file = [
                    'name' => $_FILES['scf_files']['name'][$i],
                    'type' => $_FILES['scf_files']['type'][$i],
                    'tmp_name' => $_FILES['scf_files']['tmp_name'][$i],
                    'error' => $_FILES['scf_files']['error'][$i],
                    'size' => $_FILES['scf_files']['size'][$i],
                ];
                $id = media_handle_sideload($file, 0);
                if (is_wp_error($id)) {
                    $errors[] = $name.' の保存に失敗しました。';
                } else {
                    $uploaded_urls[] = wp_get_attachment_url($id);
                }
            }
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
        if ($uploaded_urls) {
            $body .= "\n--- 添付ファイル ---\n".implode("\n", $uploaded_urls);
        }
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

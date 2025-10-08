<?php
/*
Plugin Name: Simple Contact Form
Plugin URI: https://e-mot.co.jp/
Description: シンプルなお問い合わせフォーム（ファイル添付・郵便番号→住所自動入力(yubinbango)対応）
Version: 1.1.0
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

function scf_enqueue_scripts() {
    // 郵便番号→住所自動入力API（yubinbango.js）
    wp_enqueue_script('yubinbango', 'https://yubinbango.github.io/yubinbango/yubinbango.js', [], null, true);
    // 独自バリデーション用
    wp_enqueue_script('scf-validate', plugins_url('validate.js', __FILE__), ['jquery'], '1.0', true);
    wp_enqueue_style('scf-style', plugins_url('style.css', __FILE__));
    // Cloudflare Turnstile
    if ( get_option('scf_turnstile_enabled', 0) ) {
        wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
    }
    // 会員登録用パスワードリアルタイムチェック（常時読み込みでも軽量）
    $reg_js_path = plugin_dir_path(__FILE__) . 'register.js';
    $reg_ver = file_exists($reg_js_path) ? filemtime($reg_js_path) : '1.0.1';
    wp_enqueue_script('scf-register', plugins_url('register.js', __FILE__), ['jquery'], $reg_ver, true);
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

function scf_render_form($atts = []) {
    ob_start();
    $pdf_icon = plugins_url('pdf-icon.png', __FILE__);
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
            <div class="scf-dropzone" tabindex="0">
                <span class="scf-drop-pc">ここにファイルをドラッグ＆ドロップ、またはクリックで選択</span>
                <span class="scf-drop-sp">タップしてファイルを選択</span>
            </div>
            <input type="file" name="scf_files[]" class="scf-file-input" multiple accept=".jpg,.jpeg,.gif,.pdf,.heic,.png" style="display:none;">
            <div class="scf-file-list"></div>
        </div>
        <label><span class="scf-required">必須</span>内容<br><textarea name="scf_content" required></textarea></label><br>
        <input type="hidden" name="scf_ajax" value="1">
        <?php echo wp_nonce_field('scf_submit', 'scf_nonce', true, false); ?>
        <!-- 注意事項 / 同意 -->
        <div class="scf-notice" style="border:1px solid #ddd;padding:30px;margin:12px 0;background:#fafafa;">
            <h3>注意事項</h3>
            <ul>
                <li>ご記入いただいたEメールアドレスに誤りがある場合や携帯電話のアドレス、システム障害等により弊社からの回答を送信できない場合があります。</li>
                <li>迷惑メールフィルターを設定している場合は、返信を受け取れない可能性がございますので、e-mot.co.jpからのメールを例外登録していただきますようお願いいたします。</li>
                <li>Eメールの特性上、送信過程で生じるメール内容の欠落や送信遅延、ウイルスの混入等の不具合に関して弊社は一切の責任を負いかねますのであらかじめご了承ください。</li>
                <li>こちらは一般ユーザーの方の為のお問い合わせフォームです。法人の方からのお問い合わせ、売込み、営業につきましては一律破棄されます。</li>
                <li>こちらの問い合わせフォームはSSL通信によって暗号化され保護されています。</li>
                <li>お客様からご提供いただいた個人情報は当社の個人情報保護方針に従い、お客様のお問い合わせ内容を確認し、ご回答するために利用することを目的としています。</li>
                <li>フォーム送信前に、<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" style="text-decoration: underline;">「プライバシーポリシー」</a>、<a href="/site-policies/" style="text-decoration: underline;">「サイトポリシー」</a> をご確認ください。送信された場合は、こちらに同意したこととなりますのでご了承ください。</li>
                <li>５営業日を過ぎても返信がない場合はお手数ですがお電話にてご連絡ください。<br>ユーザーサポートダイヤル：<a href="tel:0256648282" style="text-decoration: underline;">0256-64-8282</a></li>
            </ul>
            <p style="margin-top:8px;"><label><input type="checkbox" name="scf_agree" value="1" required> 注意事項に同意します。</label></p>
        </div>
        <?php if ( get_option('scf_turnstile_enabled', 0) ) :
            $sitekey = esc_attr( get_option('scf_turnstile_sitekey', '0x4AAAAAAB4PWT_eW58sqk4P') );
        ?>
            <div class="cf-turnstile" data-sitekey="<?php echo $sitekey; ?>" data-theme="light" data-callback="__scf_turnstile_callback"></div>
            <input type="hidden" name="cf_turnstile_token" id="cf_turnstile_token" value="">
            <script>
            window.__scf_turnstile_callback = function(token) {
                var el = document.getElementById('cf_turnstile_token');
                if (el) el.value = token;
            };
            </script>
        <?php endif; ?>
        <button type="submit" class="scf-btn-primary" data-scf-phase="input">確認</button>
    </form>
    <div class="scf-confirm" style="display:none;">
        <h3 style="margin-top:0;">入力内容の確認</h3>
        <div class="scf-confirm-table"></div>
        <div class="scf-confirm-actions" style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">
            <button type="button" class="scf-btn-back" style="background:#777;">修正する</button>
            <button type="button" class="scf-btn-send" style="background:#0073aa;">送信</button>
        </div>
    </div>
    <div class="scf-complete" style="display:none;">
        <h3 style="margin-top:0;">送信が完了しました</h3>
        <div class="scf-complete-message"></div>
        <div style="margin-top:24px;">
            <a href="/" class="scf-btn-top" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 28px;border-radius:4px;text-decoration:none;">トップに戻る</a>
        </div>
    </div>
    <div class="scf-message" style="margin-top:16px;"></div>
    <script>window.scfHomeUrl = <?php echo json_encode( home_url('/') ); ?>;</script>
    <div class="scf-modal" style="display:none;">
        <div class="scf-modal-overlay"></div>
        <div class="scf-modal-dialog" role="dialog" aria-modal="true" aria-live="assertive">
            <div class="scf-modal-body"></div>
            <div class="scf-modal-actions">
                <button type="button" class="scf-modal-close">閉じる</button>
            </div>
        </div>
    </div>
    <script>window.scfTwoStepFlow = true;</script>
    <script>window.scfFileThumb = window.scfFileThumb || {}; window.scfFileThumb.pdfIcon = <?php echo json_encode($pdf_icon); ?>;</script>
    <?php
    return ob_get_clean();
}
add_shortcode('simple_contact_form', 'scf_render_form');
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

// テーブル作成・アップグレード関数は includes/module-inquiries.php に移管しました。

/**
 * Simple spam check. Returns array: [is_spam(bool), engine(string), note(string)].
 * - By default performs keyword check in PHP.
 * - If option 'scf_use_python_spam' is true and 'scf_python_path' is set, it will attempt to call Python script and prefer its result.
 */

// お問い合わせ管理メニュー追加
add_action('admin_menu', function() {
    add_menu_page(
        'お問い合わせ管理',
        'お問い合わせ管理',
        'manage_options',
        'scf_inquiry_list',
        'scf_admin_inquiry_list_page',
        'dashicons-email-alt2',
        26
    );
    add_submenu_page(
        'scf_inquiry_list',
        'お問い合わせ設定',
        '設定',
        'manage_options',
        'scf_inquiry_settings',
        'scf_admin_inquiry_settings_page'
    );
    // 詳細ページ（一覧からの遷移先）
    add_submenu_page(
        'scf_inquiry_list',
        'お問い合わせ詳細',
        'お問い合わせ詳細',
        'manage_options',
        'scf_inquiry_view',
        'scf_admin_inquiry_view_page'
    );
    // spam_list管理サブメニュー
    add_submenu_page(
        'scf_inquiry_list',
        'spam_list管理',
        'spam_list管理',
        'manage_options',
        'scf_spam_list',
        function() {
            require_once plugin_dir_path(__FILE__) . 'includes/admin-spam-list.php';
            scf_admin_spam_list_page();
        }
    );
});
// 管理画面の実装は includes/admin-inquiries.php, includes/admin-settings.php に移管しました。

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

// ファイルアップロード用JSを追加
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('scf-file-upload', plugins_url('file-upload.js', __FILE__), ['jquery'], '1.0', true);
    wp_enqueue_style('scf-file-thumb', plugins_url('file-thumb.css', __FILE__));
});

// Ajax送信時のバリデーション・メール送信処理
add_action('init', function() {
    if (isset($_POST['scf_ajax']) && $_POST['scf_ajax'] == '1') {
        // nonce check: allow processing only when nonce is valid
        if (empty($_POST['scf_nonce']) || !wp_verify_nonce($_POST['scf_nonce'], 'scf_submit')) {
            wp_send_json_error(['message' => '無効なリクエストです。']);
            exit;
        }
        // spam_listテーブルの自動作成（問合せ時に必ず存在するよう保証）
    scf_create_spam_list_table();
    scf_upgrade_spam_list_schema();
        // Cloudflare Turnstile server-side verification (when enabled)
        if ( get_option('scf_turnstile_enabled', 0) ) {
            $token = isset($_POST['cf_turnstile_token']) ? trim($_POST['cf_turnstile_token']) : '';
            $secret = trim(get_option('scf_turnstile_secret', '0x4AAAAAAB4PWQ247r0OqYHTh3uHTDKkBmI'));
            if ($token === '') {
                wp_send_json_error(['message' => 'Turnstile の検証が必要です。']);
                exit;
            }
            // validate with Cloudflare
            $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ],
                'timeout' => 10,
            ]);
            if ( is_wp_error($resp) ) {
                wp_send_json_error(['message' => 'Turnstile の検証に失敗しました（通信エラー）。']);
                exit;
            }
            $body = wp_remote_retrieve_body($resp);
            $data = json_decode($body, true);
            if ( empty($data) || empty($data['success']) || $data['success'] !== true ) {
                wp_send_json_error(['message' => 'Turnstile の検証に失敗しました。']);
                exit;
            }
        }
        $required = ['scf_name','scf_email','scf_email_confirm','scf_zip','scf_address','scf_tel','scf_inquiry','scf_product','scf_content'];
        $errors = [];
        foreach ($required as $key) {
            if (empty($_POST[$key])) {
                $errors[] = $key . 'は必須です。';
            }
        }
        // agreement checkbox required
        if ( empty($_POST['scf_agree']) || $_POST['scf_agree'] != '1' ) {
            $errors[] = '注意事項に同意いただく必要があります。';
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
    $uploaded_info = [];
    $uploaded_paths = [];
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
                    $url = wp_get_attachment_url($id);
                    $mime = get_post_mime_type($id);
                    $thumb = '';
                    if (strpos($mime, 'image/') === 0) {
                        $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                        $thumb = $thumb ? $thumb[0] : $url;
                    } elseif ($mime === 'application/pdf') {
                        $thumb = plugins_url('pdf-icon.png', __FILE__);
                    }
                    $uploaded_info[] = [
                        'url' => $url,
                        'name' => $name,
                        'thumb' => $thumb,
                        'mime' => $mime,
                        'id' => $id,
                    ];
                    $uploaded_urls[] = $url;
                    $uploaded_paths[] = get_attached_file($id);
                    update_post_meta($id, '_scf_uploaded', '1');
                }
            }
        }
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode("\n", $errors)]);
        }
    // お問い合わせ番号生成
    $inquiry_no = date('ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));

    // Ensure table & schema exists before insert (旧バージョン -> 新バージョン移行時の不足カラム対策)
    scf_create_table();
    scf_upgrade_table_schema();
    // spam 判定（DBに保存する値を先に決める）
    $combined_for_spam = trim(
        sanitize_text_field($_POST['scf_inquiry']) . " \n" .
        sanitize_text_field($_POST['scf_product']) . " \n" .
        sanitize_textarea_field($_POST['scf_content'])
    );
    list($is_spam_flag, $spam_engine, $spam_note) = scf_check_spam($combined_for_spam);
    // DB保存
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    $scf_insert_data = [
        'inquiry_no' => $inquiry_no,
        'name' => sanitize_text_field($_POST['scf_name']),
        'email' => sanitize_email($_POST['scf_email']),
        'zip' => sanitize_text_field($_POST['scf_zip']),
        'address' => sanitize_text_field($_POST['scf_address']),
        'tel' => sanitize_text_field($_POST['scf_tel']),
        'inquiry' => sanitize_text_field($_POST['scf_inquiry']),
        'product' => sanitize_text_field($_POST['scf_product']),
        'date' => sanitize_text_field($_POST['scf_date']),
        'shop' => sanitize_text_field($_POST['scf_shop']),
        'content' => sanitize_textarea_field($_POST['scf_content']),
        'files' => $uploaded_info ? maybe_serialize($uploaded_info) : '',
        'is_spam' => $is_spam_flag ? 1 : 0,
        'spam_note' => $spam_note,
        'spam_engine' => $spam_engine,
    ];
    $wpdb->insert($table, $scf_insert_data);
    $scf_inquiry_id = $wpdb->insert_id;
    $scf_retry = false;
    if ( $wpdb->last_error && strpos($wpdb->last_error, 'Unknown column') !== false ) {
        // カラム不足がまだ残っている可能性 -> 再アップグレードして 1 回だけリトライ
        scf_upgrade_table_schema();
        $wpdb->insert($table, $scf_insert_data);
        $scf_inquiry_id = $wpdb->insert_id;
        $scf_retry = true;
    }
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[scf] inserted id=' . intval($wpdb->insert_id) . ($scf_retry ? ' (retry='.($wpdb->last_error?'fail':'ok').')' : ''));
        if ( $wpdb->last_error ) {
            error_log('[scf] db error: ' . $wpdb->last_error);
        }
    }

    // spam_listへ自動登録
    if ($scf_inquiry_id) {
        $spam_table = 'spam_list';
        $spam_class = $is_spam_flag ? 'spam' : 'ham';
        $spam_message = $combined_for_spam;
        $wpdb->insert($spam_table, [
            'inquiry_id' => $scf_inquiry_id,
            'class' => $spam_class,
            'message' => $spam_message,
        ]);
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('[scf] spam_list insert: inquiry_id=' . intval($scf_inquiry_id) . ' class=' . $spam_class);
        }
    }
        // 管理者宛メール
    $to = get_option('scf_support_mail', 'support@e-m.co.jp');
        $subject = '[お問い合わせ] ' . sanitize_text_field($_POST['scf_inquiry']) . '：' . $inquiry_no;
        $body = "お問い合わせ番号: {$inquiry_no}\n".
                "お名前: {$_POST['scf_name']}\n".
                "メール: {$_POST['scf_email']}\n".
                "郵便番号: {$_POST['scf_zip']}\n".
                "住所: {$_POST['scf_address']}\n".
                "電話番号: {$_POST['scf_tel']}\n".
                "お問い合わせ種別: {$_POST['scf_inquiry']}\n".
                "商品名: {$_POST['scf_product']}\n".
                "お買い上げ日: {$_POST['scf_date']}\n".
                "ご購入店舗名: {$_POST['scf_shop']}\n".
                "内容: {$_POST['scf_content']}\n";
        if ($uploaded_info) {
            $body .= "\n--- 添付ファイル ---\n";
            foreach ($uploaded_info as $f) {
                $body .= $f['name'] . ': ' . $f['url'] . "\n";
            }
        }
        $headers = ['From: '.get_bloginfo('name').' <'.$to.'>'];
        if ($is_spam_flag) {
            // spam の場合は管理者通知を送らない（運用上はDBには記録する）
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[scf] submission marked as spam; engine=' . $spam_engine . ' note=' . $spam_note);
            }
            $sent = true; // 管理者通知はスキップしたが処理は成功扱いとする
        } else {
            $sent = wp_mail($to, $subject, $body, $headers);
        }

        // お客様控えメール
        $user_email = sanitize_email($_POST['scf_email']);
        $user_subject = '【' . get_bloginfo('name') . '】お問い合わせ受付完了（お問い合わせ番号:'.$inquiry_no.'）';
        $user_body = $_POST['scf_name'] . " 様\n\n".
            "この度はお問い合わせいただき誠にありがとうございます。\n".
            "下記の内容で受付いたしました。\n".
            "担当者より折り返しご連絡いたしますので、今しばらくお待ちください。\n\n".
            "--- お問い合わせ内容 ---\n".
            "お問い合わせ番号: {$inquiry_no}\n".
            "お名前: {$_POST['scf_name']}\n".
            "メール: {$_POST['scf_email']}\n".
            "郵便番号: {$_POST['scf_zip']}\n".
            "住所: {$_POST['scf_address']}\n".
            "電話番号: {$_POST['scf_tel']}\n".
            "お問い合わせ種別: {$_POST['scf_inquiry']}\n".
            "商品名: {$_POST['scf_product']}\n".
            "お買い上げ日: {$_POST['scf_date']}\n".
            "ご購入店舗名: {$_POST['scf_shop']}\n".
            "内容: {$_POST['scf_content']}\n";
        if ($uploaded_info) {
            $user_body .= "\n--- 添付ファイル ---\n";
            foreach ($uploaded_info as $f) {
                $user_body .= $f['name'] . "\n";
            }
        }
        $user_body .= "\n\n※本メールは自動送信です。\n"
            . "万一、内容にお心当たりがない場合は本メールを破棄してください。\n"
            . "────────────────────\n"
            . get_bloginfo('name') . "\n"
            . home_url() . "\n";
        $user_headers = ['From: '.get_bloginfo('name').' <'.$to.'>'];
        wp_mail($user_email, $user_subject, $user_body, $user_headers, $uploaded_paths);

        if ($sent) {
            // 改行を実際の LF として埋め込み（JS側で \n -> <br> 変換）
            $msg = "送信が完了しました。\nお問い合わせ番号: {$inquiry_no}\nご入力いただいたメールアドレス宛に控えを送信しました。";
            wp_send_json_success(['message' => $msg]);
        } else {
            wp_send_json_error(['message' => '送信に失敗しました。']);
        }
        exit;
    }
});

/**
 * =============================
 * User Login / Registration (migrated from theme functions.php)
 * =============================
 * Provides shortcodes:
 *  - [scf_login_form] (alias: [mot_login_form])
 *  - [scf_register_form] (alias: [mot_register_form])
 * Adds redirect for logged-in users visiting /login/ or /register/ paths.
 * Uses existing Turnstile options: scf_turnstile_enabled, scf_turnstile_sitekey, scf_turnstile_secret.
 */

// Redirect logged-in users away from auth pages
function scf_redirect_logged_in_from_auth_pages() {
    if ( is_admin() || wp_doing_ajax() || ( defined('DOING_CRON') && DOING_CRON ) ) return;
    if ( defined('REST_REQUEST') && REST_REQUEST ) return;
    if ( ! is_user_logged_in() ) return;
    $request = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    if ($request === '') return;
    $path = strtok($request, '?');
    $path = rtrim($path, '/') . '/';
    $targets = ['/login/','/register/','/wp-login.php'];
    foreach ($targets as $t) {
        if (strpos($path, $t) !== false) {
            $dest = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
            wp_safe_redirect($dest);
            exit;
        }
    }
}
add_action('template_redirect', 'scf_redirect_logged_in_from_auth_pages', 1);

// Helper: Turnstile sitekey
function scf_get_turnstile_sitekey() {
    if (!get_option('scf_turnstile_enabled', 0)) return '';
    return trim(get_option('scf_turnstile_sitekey', ''));
}
function scf_get_turnstile_secret() {
    if (!get_option('scf_turnstile_enabled', 0)) return '';
    return trim(get_option('scf_turnstile_secret', ''));
}

/**
 * Cloudflare Turnstile 検証 (共通関数)
 * @param string $token  フロントから送られたトークン
 * @param string $context 'register' | 'contact' など呼び出し元識別用
 * @return array [bool success, array raw_response_json_or_error]
 */
function scf_turnstile_verify($token, $context = 'generic'){ 
    $secret = scf_get_turnstile_secret();
    if (!$secret) return [true, ['skipped' => 'disabled']]; // 未有効化なら成功扱い
    $token = trim($token);
    if ($token === '') return [false, ['error' => 'empty-token']];
    $body_args = [ 'secret' => $secret, 'response' => $token ];
    $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 15,
        'body'    => $body_args,
    ]);
    if (is_wp_error($resp)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SCF TurnstileDBG] context='.$context.' http_error msg='.$resp->get_error_message().' token_len='.strlen($token));
        }
        return [false, ['wp_error' => $resp->get_error_message()]];
    }
    $http_code = wp_remote_retrieve_response_code($resp);
    $raw = wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);
    $success = isset($json['success']) ? (bool)$json['success'] : false;
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $codes = isset($json['error-codes']) ? $json['error-codes'] : [];
        if (!is_array($codes)) $codes = [$codes];
        error_log('[SCF TurnstileDBG] context='.$context.' http_code='.$http_code.' success='.( $success ? '1':'0').' token_len='.strlen($token).' codes='.wp_json_encode($codes).' raw='.substr($raw,0,300));
    }
    return [$success, $json ? $json : ['raw' => $raw, 'http_code' => $http_code]];
}

// Social login rendering (Nextend) if available
function scf_render_social_login_block() {
    if (shortcode_exists('nextend_social_login')) {
        return '<div class="scf-nextend-social-login">' . do_shortcode('[nextend_social_login]') . '</div>';
    }
    return '';
}

// Login form shortcode
function scf_login_form_shortcode($atts) {
    if (is_user_logged_in()) {
        return '<p>' . esc_html__('ログイン中です。', 'simple-contact-form') . '</p>';
    }
    $redirect = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : ( function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/') );
    // 失敗フラグと前回ユーザー名
    $failed = ( isset($_GET['scf_login']) && $_GET['scf_login'] === 'failed' );
    $attempt_user = isset($_GET['u']) ? sanitize_user(wp_unslash($_GET['u']), true) : '';
    $args = [
        'redirect'       => $redirect,
        'label_username' => __('Eメールまたはユーザー名', 'simple-contact-form'),
        'label_log_in'   => __('ログイン', 'simple-contact-form'),
        'value_username' => $attempt_user,
        // password / remember はデフォルト
    ];
    // このフォーム由来を識別する hidden フィールドを差し込むフィルターを一時追加
    $hidden_cb = function($content, $cb_args){
        return $content . '<input type="hidden" name="scf_custom_login" value="1" />';
    };
    add_filter('login_form_middle', $hidden_cb, 10, 2);
    ob_start();
    if ($failed) {
        echo '<div class="scf-login-errors"><p class="scf-error" style="color:#b32d2e;">' . esc_html__('ユーザー名またはパスワードが正しくありません。', 'simple-contact-form') . '</p></div>';
    }
    wp_login_form($args);
    remove_filter('login_form_middle', $hidden_cb, 10);
    return ob_get_clean();
}
add_shortcode('scf_login_form', 'scf_login_form_shortcode');
add_shortcode('mot_login_form', 'scf_login_form_shortcode'); // backward compatibility

/**
 * ログイン失敗時にデフォルトの wp-login.php へ留まらず /login/ (ショートコードページ) へ戻す。
 * フォームに挿入した hidden フィールド scf_custom_login=1 で当プラグインフォーム由来を判定。
 */
function scf_login_failed_redirect($username){
    // POST が無ければ（他経路/API）スキップ
    if (empty($_POST) || !isset($_POST['scf_custom_login'])) {
        return; // WooCommerce や 他プラグイン用ログインは影響しない
    }
    // redirect_to のバリデーション
    $redirect_to = '';
    if (isset($_POST['redirect_to'])) {
        $candidate = esc_url_raw(wp_unslash($_POST['redirect_to']));
        $validated = wp_validate_redirect($candidate, '');
        if ($validated) $redirect_to = $validated;
    }
    $params = [ 'scf_login' => 'failed' ];
    if ($username) {
        $params['u'] = rawurlencode($username);
    }
    if ($redirect_to) {
        $params['redirect_to'] = rawurlencode($redirect_to);
    }
    $login_page = home_url('/login/');
    $url = add_query_arg($params, $login_page);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[SCF LoginDBG] failed login for '.$username.' redirecting to '.$url);
    }
    wp_safe_redirect($url);
    exit;
}
add_action('wp_login_failed', 'scf_login_failed_redirect', 1, 1);

// Registration form shortcode
function scf_register_form_shortcode($atts) {
    if (is_user_logged_in()) {
        return '<p>' . esc_html__('ログイン中です。', 'simple-contact-form') . '</p>';
    }
    $errors = [];
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['scf_register_nonce']) && wp_verify_nonce($_POST['scf_register_nonce'], 'scf_register')) {
        if ( ! defined('SCF_REGISTER_FORM_SUBMISSION') ) {
            define('SCF_REGISTER_FORM_SUBMISSION', true); // 競合バイパス用コンテキストフラグ
        }
        $email = isset($_POST['scf_email']) ? sanitize_email($_POST['scf_email']) : '';
        $username = isset($_POST['scf_username']) ? sanitize_user($_POST['scf_username']) : '';
        $password = isset($_POST['scf_password']) ? $_POST['scf_password'] : '';
        $password_confirm = isset($_POST['scf_password_confirm']) ? $_POST['scf_password_confirm'] : '';
        if (empty($email) || !is_email($email)) $errors[] = __('有効なメールアドレスを入力してください。', 'simple-contact-form');
        // パスワードポリシー: 8文字以上 / 大文字 / 小文字 / 数字 / 記号
        if (empty($password)) {
            $errors[] = __('パスワードを入力してください。', 'simple-contact-form');
        } else {
            if (strlen($password) < 8) $errors[] = __('パスワードは8文字以上必要です。', 'simple-contact-form');
            if (!preg_match('/[A-Z]/', $password)) $errors[] = __('パスワードには英大文字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/[a-z]/', $password)) $errors[] = __('パスワードには英小文字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/\d/', $password)) $errors[] = __('パスワードには数字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = __('パスワードには記号が少なくとも1文字必要です。', 'simple-contact-form');
            if (preg_match('/\s/', $password)) $errors[] = __('パスワードに空白文字は使用できません。', 'simple-contact-form');
            // 簡易強度スコア（zxcvbn はサーバー利用しないため近似）
            $score = 0; // 0~4
            $classes = 0;
            if (preg_match('/[A-Z]/',$password)) $classes++;
            if (preg_match('/[a-z]/',$password)) $classes++;
            if (preg_match('/\d/',$password)) $classes++;
            if (preg_match('/[^A-Za-z0-9]/',$password)) $classes++;
            // 長さと複雑度でスコア近似
            if ($classes >= 3 && strlen($password) >= 12) $score = 4;
            elseif ($classes >= 3 && strlen($password) >= 10) $score = 3;
            elseif ($classes >= 2 && strlen($password) >= 8) $score = 2;
            elseif ($classes >= 2) $score = 1; else $score = 0;
            $required_score = intval(get_option('scf_pw_strength_min', 0));
            if ($required_score > 0 && $score < $required_score) {
                $errors[] = sprintf(__('パスワード強度が不足しています。(必要:%d / 現在:%d)', 'simple-contact-form'), $required_score, $score);
            }
        }
        if ($password !== $password_confirm) $errors[] = __('パスワード（確認）が一致しません。', 'simple-contact-form');
        if (empty($username) && $email) {
            $username = sanitize_user(current(explode('@', $email)), true);
        }
        // Turnstile verification (if enabled)
        $secret = scf_get_turnstile_secret();
        $scf_turnstile_verified = false; // ローカルフラグ
        if ($secret) {
            $token = '';
            $candidate_keys = [ 'cf-turnstile-response','cf_turnstile_response','turnstile-response' ];
            foreach ($candidate_keys as $k) { if (!empty($_POST[$k])) { $token = sanitize_text_field($_POST[$k]); break; } }
            if (!$token) {
                $errors[] = __('Turnstile の検証トークンが見つかりませんでした。', 'simple-contact-form');
            } else {
                list($ok, $raw) = scf_turnstile_verify($token, 'register');
                if (!$ok) {
                    // エラーコードに応じたメッセージ差し替え
                    $codes = [];
                    if (isset($raw['error-codes'])) { $codes = (array)$raw['error-codes']; }
                    $msg = __('Turnstile の検証に失敗しました。', 'simple-contact-form');
                    if ($codes) {
                        if (in_array('timeout-or-duplicate', $codes, true)) {
                            $msg = __('Turnstile がタイムアウトまたは重複しました。ページを再読み込みし、再度チェックしてください。', 'simple-contact-form');
                        } elseif (in_array('invalid-input-secret', $codes, true)) {
                            $msg = __('Turnstile シークレットキーが無効です。管理者に連絡してください。', 'simple-contact-form');
                        } elseif (in_array('invalid-input-response', $codes, true)) {
                            $msg = __('Turnstile 応答トークンが無効です。再度チェックしてください。', 'simple-contact-form');
                        }
                    }
                    $errors[] = $msg;
                }
                if ($ok) {
                    $scf_turnstile_verified = true;
                    $GLOBALS['scf_turnstile_verified'] = true; // フィルター側参照
                }
            }
        }
        if (empty($errors)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SCF RegisterDBG] attempting wc_create_new_customer email='.$email.' username='.$username.' pw_len='.strlen($password));
            }
            if (function_exists('wc_create_new_customer')) {
                $user_id = wc_create_new_customer($email, $username, $password);
                if (is_wp_error($user_id)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SCF RegisterDBG] wc_create_new_customer error: '.$user_id->get_error_code().' msg='.$user_id->get_error_message());
                    }
                    $errors[] = $user_id->get_error_message();
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SCF RegisterDBG] registration success user_id='.$user_id);
                    }
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);
                    $scf_user_obj = get_user_by('id', $user_id);
                    do_action('wp_login', $username, $scf_user_obj);
                    wp_safe_redirect(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/'));
                    exit;
                }
            } else {
                $errors[] = __('WooCommerce functions not available.', 'simple-contact-form');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SCF RegisterDBG] pre-wc errors='.wp_json_encode($errors));
            }
        }
    }
    ob_start();
    if (!empty($errors)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SCF RegisterDBG] output errors='.wp_json_encode($errors));
        }
        echo '<div class="scf-register-errors">';
        foreach ($errors as $e) echo '<p class="scf-error">' . esc_html($e) . '</p>';
        echo '</div>';
    }
    
    $sitekey = scf_get_turnstile_sitekey();
    $required_score = intval(get_option('scf_pw_strength_min', 0));
    ?>
    <form method="post" class="scf-register-form">
        <?php wp_nonce_field('scf_register', 'scf_register_nonce'); ?>
        <script>window.scfPwRequiredScore = <?php echo (int) $required_score; ?>;</script>
        <p>
            <label for="scf_email"><?php esc_html_e('Eメール', 'simple-contact-form'); ?></label><br>
            <input type="email" name="scf_email" id="scf_email" required value="<?php echo esc_attr(isset($_POST['scf_email']) ? $_POST['scf_email'] : ''); ?>">
        </p>
        <p>
            <label for="scf_username"><?php esc_html_e('ユーザー名 (任意)', 'simple-contact-form'); ?></label><br>
            <input type="text" name="scf_username" id="scf_username" value="<?php echo esc_attr(isset($_POST['scf_username']) ? $_POST['scf_username'] : ''); ?>">
        </p>
        <p>
            <label for="scf_password"><?php esc_html_e('パスワード', 'simple-contact-form'); ?></label><br>
            <input type="password" name="scf_password" id="scf_password" required>
        </p>
        <p>
            <label for="scf_password_confirm"><?php esc_html_e('パスワード（確認）', 'simple-contact-form'); ?></label><br>
            <input type="password" name="scf_password_confirm" id="scf_password_confirm" required>
        </p>
        <div class="scf-password-helper" style="display:none;margin:12px 0;padding:12px 14px;background:rgba(0,0,0,.05);border-radius:10px;">
            <div class="scf-password-policy" style="margin:0 0 10px;">
                <p style="margin:0 0 6px;font-weight:bold;">パスワード要件:</p>
                <ul style="list-style:disc;margin:0 0 0 20px;padding:0;">
                    <li data-rule="length">8文字以上</li>
                    <li data-rule="upper">英大文字を含む (A-Z)</li>
                    <li data-rule="lower">英小文字を含む (a-z)</li>
                    <li data-rule="digit">数字を含む (0-9)</li>
                    <li data-rule="symbol">記号を含む (!@#$% など)</li>
                    <li data-rule="match">確認欄と一致</li>
                </ul>
            </div>
            <div class="scf-password-strength" style="margin:4px 0 0;">
                <div class="scf-strength-bar" style="height:6px;background:#e5e5e5;border-radius:3px;overflow:hidden;position:relative;">
                    <span class="scf-strength-fill" style="display:block;height:100%;width:0;background:#d9534f;transition:width .3s,background .3s;"></span>
                </div>
                <p class="scf-strength-text" style="font-size:12px;margin:6px 0 0;color:#555;">強度: -</p>
            </div>
        </div>
                <?php if ($sitekey) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($sitekey); ?>" data-callback="scfRegTurnstileSuccess" data-expired-callback="scfRegTurnstileExpired"></div>
                        <script>
                        window.scfRegTurnstileSuccess = function(){ /* Cloudflare が自動で cf-turnstile-response を挿入 */ };
                        window.scfRegTurnstileExpired = function(){ var f=document.querySelector('input[name="cf-turnstile-response"]'); if(f){ f.value=''; } };
                        (function(){
                            // 送信直前にトークン存在チェック
                            document.addEventListener('submit', function(e){
                                var form = e.target;
                                if(!form.classList || !form.classList.contains('scf-register-form')) return;
                                var field = form.querySelector('input[name="cf-turnstile-response"]');
                                if(!field || !field.value){
                                    e.preventDefault();
                                    alert('セキュリティ確認（Turnstile）を完了してください。');
                                }
                            }, true);
                        })();
                        </script>
                <?php endif; ?>
        <p><button type="submit"><?php esc_html_e('登録', 'simple-contact-form'); ?></button></p>
    </form>
    <?php
    echo scf_render_social_login_block();
    return ob_get_clean();
}
add_shortcode('scf_register_form', 'scf_register_form_shortcode');
add_shortcode('mot_register_form', 'scf_register_form_shortcode'); // backward compatibility

/**
 * Redirect non-logged users visiting My Account to custom /login/ page with redirect_back param.
 * (Migrated from theme)
 */
function scf_redirect_myaccount_to_custom_login(){
    if ( function_exists('is_account_page') && is_account_page() && ! is_user_logged_in() ) {
        $login_slug = '/login/';
        $register_slug = '/register/';
        $request = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($request, $login_slug) !== false || strpos($request, $register_slug) !== false ) {
            return; // already on login/register page
        }
        $login_page = home_url($login_slug);
        $redirect_to = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $login_page);
        wp_safe_redirect($url);
        exit;
    }
}
add_action('template_redirect', 'scf_redirect_myaccount_to_custom_login', 5);

/**
 * Social login rendering (shared) - already have scf_render_social_login_block for forms.
 * This block adds insertion into WooCommerce login and checkout login forms (migrated from theme).
 */
function scf_print_nextend_social_login(){
    if (shortcode_exists('nextend_social_login')) {
        echo '<div class="scf-nextend-social-login">' . do_shortcode('[nextend_social_login]') . '</div>';
    }
}
add_action('woocommerce_login_form_end', 'scf_print_nextend_social_login');
add_action('woocommerce_checkout_login_form', 'scf_print_nextend_social_login');

// Inline styles for social login layout (migrated)
function scf_nextend_styles(){
    $css = ' .scf-nextend-social-login{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:.6rem;text-align:center;max-width:100%;box-sizing:border-box;margin:0 auto 1rem}'
        .' .scf-nextend-social-login a,.scf-nextend-social-login button,.scf-nextend-social-login .nsl-button{width:auto!important;margin:2.5px auto!important;white-space:normal!important}'
        .' .scf-nextend-social-login img{max-width:100%;height:auto;display:block}';
    if ( wp_style_is('hello-elementor-child-style','enqueued') || wp_style_is('hello-elementor-child-style','registered') ) {
        wp_add_inline_style('hello-elementor-child-style', $css);
    } else {
        add_action('wp_head', function() use ($css){ echo '<style>'.$css.'</style>'; }, 100);
    }
}
add_action('wp_enqueue_scripts', 'scf_nextend_styles', 30);

/**
 * SiteGuard CAPTCHA insertion on WooCommerce login form (migrated from theme)
 */
function scf_siteguard_captcha_login(){
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
        return;
    }
    global $siteguard_captcha, $siteguard_config;
    if ( isset( $siteguard_captcha ) && is_object( $siteguard_captcha )
        && method_exists( $siteguard_captcha, 'handler_login_form' )
        && isset( $siteguard_config )
        && '1' === $siteguard_config->get( 'captcha_enable' )
        && '0' !== $siteguard_config->get( 'captcha_login' ) ) {
        ob_start();
        $siteguard_captcha->handler_login_form();
        echo ob_get_clean();
    }
}
add_action('woocommerce_login_form', 'scf_siteguard_captcha_login', 20);

/**
 * ソーシャルログイン(Nextend等)経由で作成されたユーザーが subscriber になるケースを補正し
 * WooCommerce の "customer" ロールへ自動昇格させる。
 *
 * 条件:
 *  - WooCommerce が有効 (customer ロールが存在)
 *  - 追加直後のユーザーが subscriber だけを持つ
 *  - 管理画面でのユーザー作成ではない (is_admin() を除外)
 *  - リクエストがフロントの認証/登録関連URLまたは Nextend のパラメータを含む
 */
function scf_force_customer_role_for_social($user_id){
    if ( is_admin() ) return; // 管理画面操作は尊重
    if ( ! function_exists('wc_get_page_permalink') ) return; // WooCommerce未導入なら何もしない
    if ( ! get_role('customer') ) return; // customer ロールが無ければ安全に終了

    $user = get_userdata($user_id);
    if ( ! $user ) return;
    // すでに他ロールを持っている/ customer なら不要
    if ( in_array('customer', (array)$user->roles, true) ) return;
    if ( count((array)$user->roles) !== 1 || ! in_array('subscriber', (array)$user->roles, true) ) return;

    // ソーシャルログインっぽいシグナルを収集
    $is_social = false;
    // Nextend 典型的パラメータ（環境により異なる場合あり）
    foreach (['nsl_provider','nsl_nonce','nsl_auth','provider'] as $k){
        if ( isset($_REQUEST[$k]) && $_REQUEST[$k] !== '' ){ $is_social = true; break; }
    }
    // HTTP_REFERER に login / register / my-account が含まれていたらフロント登録とみなす
    if ( ! $is_social && ! empty($_SERVER['HTTP_REFERER']) ) {
        $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
        if ( $ref_path && preg_match('#/(login|register|my-account)#', $ref_path) ) {
            $is_social = true; // 厳密ではないがフロント登録補正目的
        }
    }
    // 追加保険: 直後のセッション/クッキーに nsl_ で始まるキーがあればヒントとする
    if ( ! $is_social ) {
        foreach ($_COOKIE as $ckey => $cval){
            if ( stripos($ckey, 'nsl_') === 0 ){ $is_social = true; break; }
        }
    }

    if ( ! $is_social ) return; // 判定できなければ何もしない（冤罪防止）

    // ロール変更
    $wp_user = new WP_User($user_id);
    $wp_user->set_role('customer');
    do_action('scf_user_promoted_to_customer_from_social', $user_id);
}
add_action('user_register','scf_force_customer_role_for_social', 200);

/**
 * WooCommerce 登録エラーから競合プラグイン(simple-cloudflare-turnstile)が追加した
 * cfturnstile_error ("人間であることを確認してください。") を、既に当プラグイン側で
 * Turnstile 成功検証済みの場合に除去する。
 */
function scf_filter_wc_errors_remove_cfturnstile($errors){
    if ( empty($errors) || ! is_object($errors) ) return $errors;
    // 成功検証フラグが無い / 当プラグインフォーム以外なら何もしない
    if ( empty($GLOBALS['scf_turnstile_verified']) || ! defined('SCF_REGISTER_FORM_SUBMISSION') ) return $errors;
    if ( method_exists($errors, 'get_error_codes') ) {
        $codes = $errors->get_error_codes();
        if ( in_array('cfturnstile_error', $codes, true) ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SCF TurnstileDBG] removing cfturnstile_error (duplicate validation)');
            }
            $errors->remove('cfturnstile_error');
        }
    }
    return $errors;
}
add_filter('woocommerce_registration_errors','scf_filter_wc_errors_remove_cfturnstile', 5, 1);

/* --------------------------------------------------------------
 * A/B/C/D: 既存ユーザーによるソーシャルアカウント後付け連携機能
 *  A: 接続 UI (Connect) をマイアカウントへ表示
 *  B: パスワード未設定ユーザーは Disconnect ボタンを隠す (孤立防止)
 *  C: 独自エンドポイント /my-account/social-connections/ 追加
 *  D: メール一致自動リンクを "verified メール" のみ許可する安全ガード
 * -------------------------------------------------------------- */

// D: メール一致自動リンク安全ガード (Nextend フィルタ) - email_verified が真の場合のみ既存ユーザーへリンク
if ( ! function_exists('scf_nsl_verified_email_autolink') ) {
    add_filter('nsl_login_user', function($user, $userdata){
        // 既に確定済みならそのまま
        if ($user instanceof WP_User) return $user;
        // Nextend が渡す構造はバージョンで差異あり。email/email_verified を想定。
        $email  = isset($userdata['email']) ? sanitize_email($userdata['email']) : '';
        $verified = false;
        if (isset($userdata['email_verified'])) {
            $verified = (bool)$userdata['email_verified'];
        } elseif (isset($userdata['verified'])) { // フォールバックキー
            $verified = (bool)$userdata['verified'];
        }
        if ($email && $verified) {
            $existing = get_user_by('email', $email);
            if ($existing && $existing->ID) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SCF SocialLink] auto-link by verified email user_id='.$existing->ID.' email='.$email);
                }
                return $existing; // 自動リンク
            }
        }
        return $user; // 条件に合わなければ Nextend の既定処理へ
    }, 10, 2);
}

// C: WooCommerce マイアカウントへ /social-connections エンドポイント追加
function scf_register_social_connections_endpoint(){
    if ( function_exists('add_rewrite_endpoint') ) {
        add_rewrite_endpoint('social-connections', EP_ROOT | EP_PAGES);
    }
}
add_action('init','scf_register_social_connections_endpoint');

// プラグイン有効化時に rewrite ルールを再生成（/my-account/social-connections/ を有効化）
if ( function_exists('register_activation_hook') ) {
    register_activation_hook(__FILE__, function(){
        // 念のためエンドポイント登録後に flush
        scf_register_social_connections_endpoint();
        flush_rewrite_rules(false);
        update_option('scf_social_connections_endpoint_last_flush', time());
    });
}
// 無効化時にルールを元に戻す（過剰 flush 防止のため一回のみ）
if ( function_exists('register_deactivation_hook') ) {
    register_deactivation_hook(__FILE__, function(){
        flush_rewrite_rules(false);
    });
}

// 既に稼働中の環境で activation フックが走らないままコード追加されたケースを救済: 一度だけ自動 flush
add_action('init', function(){
    // 一度も自動修復していない & ルールに social-connections が無いなら (管理画面アクセス前でも) 一回だけ flush
    if ( ! get_option('scf_social_connections_endpoint_autofixed') ) {
        $rules = get_option('rewrite_rules');
        $has = false;
        if ( is_array($rules) ) {
            foreach($rules as $regex=>$query){
                if (strpos($regex,'social-connections') !== false || strpos($query,'social-connections') !== false){
                    $has = true; break;
                }
            }
        } elseif ( is_string($rules) ) {
            $has = (strpos($rules,'social-connections') !== false);
        }
        if ( ! $has ) {
            scf_register_social_connections_endpoint();
            flush_rewrite_rules(false);
            update_option('scf_social_connections_endpoint_last_flush', time());
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[SCF SocialLink] auto flushed rewrite rules (front-end) for social-connections endpoint');
            }
        }
        update_option('scf_social_connections_endpoint_autofixed', 1);
    }
}, 30);

// WooCommerce が内部でクエリ変数を解釈できるよう明示的に登録 (一部環境の競合回避)
add_filter('woocommerce_get_query_vars', function($vars){
    if (! isset($vars['social-connections'])) {
        $vars['social-connections'] = 'social-connections';
    }
    return $vars;
});

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

// ML retrain schedule
if ( ! wp_next_scheduled( 'scf_ml_retrain_event' ) ) {
    wp_schedule_event( strtotime('03:30:00'), 'daily', 'scf_ml_retrain_event' );
}

add_action( 'scf_ml_retrain_event', function() {
    $ml_dir = '/home/xs683807/e-mot.co.jp/public_html/dev/wp-content/plugins/simple-contact-form/ml';
    $python = '/home/xs683807/miniconda3/bin/python';
    $cmd = "cd {$ml_dir} && PYTHON_BIN={$python} ./retrain_deploy.sh";
    exec($cmd . ' > /dev/null 2>&1 &');
});


/**
 * spam_listテーブルのスキーマ自動アップグレード（createdカラム追加）
 */
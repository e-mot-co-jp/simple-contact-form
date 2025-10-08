<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('scf_handle_ajax_submission')) {
function scf_handle_ajax_submission(){
    if (!isset($_POST['scf_ajax']) || $_POST['scf_ajax'] != '1') return;
    // nonce check
    if (empty($_POST['scf_nonce']) || !wp_verify_nonce($_POST['scf_nonce'], 'scf_submit')) {
        wp_send_json_error(['message' => '無効なリクエストです。']);
        exit;
    }
    // spam_listテーブルの自動作成
    if (function_exists('scf_create_spam_list_table')) scf_create_spam_list_table();
    if (function_exists('scf_upgrade_spam_list_schema')) scf_upgrade_spam_list_schema();

    // Cloudflare Turnstile 検証
    if ( get_option('scf_turnstile_enabled', 0) ) {
        $token = isset($_POST['cf_turnstile_token']) ? trim($_POST['cf_turnstile_token']) : '';
        $secret = trim(get_option('scf_turnstile_secret', '0x4AAAAAAB4PWQ247r0OqYHTh3uHTDKkBmI'));
        if ($token === '') {
            wp_send_json_error(['message' => 'Turnstile の検証が必要です。']);
            exit;
        }
        $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [ 'secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '' ],
            'timeout' => 10,
        ]);
        if ( is_wp_error($resp) ) {
            wp_send_json_error(['message' => 'Turnstile の検証に失敗しました（通信エラー）。']);
            exit;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ( empty($data) || empty($data['success']) ) {
            wp_send_json_error(['message' => 'Turnstile の検証に失敗しました。']);
            exit;
        }
    }

    $required = ['scf_name','scf_email','scf_email_confirm','scf_zip','scf_address','scf_tel','scf_inquiry','scf_product','scf_content'];
    $errors = [];
    foreach ($required as $key) { if (empty($_POST[$key])) { $errors[] = $key . 'は必須です。'; } }
    if ( empty($_POST['scf_agree']) || $_POST['scf_agree'] != '1' ) { $errors[] = '注意事項に同意いただく必要があります。'; }
    if ($_POST['scf_email'] !== $_POST['scf_email_confirm']) { $errors[] = 'メールアドレスが一致しません。'; }

    // ファイル検証
    $uploaded_info = [];
    $uploaded_paths = [];
    if (!empty($_FILES['scf_files'])) {
        $allowed = ['jpg','jpeg','gif','pdf','heic','png'];
        $max_size = 40 * 1024 * 1024; // 40MB
        foreach ($_FILES['scf_files']['name'] as $i => $name) {
            if (!$_FILES['scf_files']['size'][$i]) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = $name.' は許可されていないファイル形式です。'; }
            if ($_FILES['scf_files']['size'][$i] > $max_size) { $errors[] = $name.' は40MBを超えています。'; }
        }
    }
    if (!empty($errors)) { wp_send_json_error(['message' => implode("\n", $errors)]); }

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
                $url = wp_get_attachment_url($id);
                $mime = get_post_mime_type($id);
                $thumb = '';
                if (strpos($mime, 'image/') === 0) {
                    $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                    $thumb = $thumb ? $thumb[0] : $url;
                } elseif ($mime === 'application/pdf') {
                    // plugin root にある pdf-icon.png を指す
                    $thumb = plugins_url('pdf-icon.png', dirname(__FILE__) . '/../simple-contact-form.php');
                }
                $uploaded_info[] = [ 'url' => $url, 'name' => $name, 'thumb' => $thumb, 'mime' => $mime, 'id' => $id ];
                $uploaded_paths[] = get_attached_file($id);
                update_post_meta($id, '_scf_uploaded', '1');
            }
        }
    }
    if (!empty($errors)) { wp_send_json_error(['message' => implode("\n", $errors)]); }

    // お問い合わせ番号
    $inquiry_no = date('ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));

    // テーブル保証
    if (function_exists('scf_create_table')) scf_create_table();
    if (function_exists('scf_upgrade_table_schema')) scf_upgrade_table_schema();

    // スパム判定
    $combined_for_spam = trim(
        sanitize_text_field($_POST['scf_inquiry']) . " \n" .
        sanitize_text_field($_POST['scf_product']) . " \n" .
        sanitize_textarea_field($_POST['scf_content'])
    );
    list($is_spam_flag, $spam_engine, $spam_note) = function_exists('scf_check_spam') ? scf_check_spam($combined_for_spam) : [false,'php','disabled'];

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
        if (function_exists('scf_upgrade_table_schema')) scf_upgrade_table_schema();
        $wpdb->insert($table, $scf_insert_data);
        $scf_inquiry_id = $wpdb->insert_id;
        $scf_retry = true;
    }
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[scf] inserted id=' . intval($wpdb->insert_id) . ($scf_retry ? ' (retry='.($wpdb->last_error?'fail':'ok').')' : ''));
        if ( $wpdb->last_error ) { error_log('[scf] db error: ' . $wpdb->last_error); }
    }

    // spam_list 登録
    if ($scf_inquiry_id) {
        $spam_table = 'spam_list';
        $spam_class = $is_spam_flag ? 'spam' : 'ham';
        $wpdb->insert($spam_table, [ 'inquiry_id' => $scf_inquiry_id, 'class' => $spam_class, 'message' => $combined_for_spam ]);
        if ( defined('WP_DEBUG') && WP_DEBUG ) { error_log('[scf] spam_list insert: inquiry_id=' . intval($scf_inquiry_id) . ' class=' . $spam_class); }
    }

    // メール送信
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
        foreach ($uploaded_info as $f) { $body .= $f['name'] . ': ' . $f['url'] . "\n"; }
    }
    $headers = ['From: '.get_bloginfo('name').' <'.$to.'>'];
    if ($is_spam_flag) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) { error_log('[scf] submission marked as spam; engine=' . $spam_engine . ' note=' . $spam_note); }
        $sent = true; // spam は管理通知スキップ
    } else {
        $sent = wp_mail($to, $subject, $body, $headers);
    }

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
        foreach ($uploaded_info as $f) { $user_body .= $f['name'] . "\n"; }
    }
    $user_body .= "\n\n※本メールは自動送信です。\n"
        . "万一、内容にお心当たりがない場合は本メールを破棄してください。\n"
        . "────────────────────\n"
        . get_bloginfo('name') . "\n"
        . home_url() . "\n";
    $user_headers = ['From: '.get_bloginfo('name').' <'.$to.'>'];
    wp_mail($user_email, $user_subject, $user_body, $user_headers, $uploaded_paths);

    if ($sent) {
        $msg = "送信が完了しました。\nお問い合わせ番号: {$inquiry_no}\nご入力いただいたメールアドレス宛に控えを送信しました。";
        wp_send_json_success(['message' => $msg]);
    } else {
        wp_send_json_error(['message' => '送信に失敗しました。']);
    }
    exit;
}
}

if ( ! has_action('init','scf_handle_ajax_submission') ) {
    add_action('init','scf_handle_ajax_submission');
}

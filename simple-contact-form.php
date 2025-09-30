<?php
/*
Plugin Name: Simple Contact Form
Plugin URI: https://example.com/
Description: シンプルなお問い合わせフォーム（ファイル添付・郵便番号→住所自動入力(yubinbango)対応）
Version: 1.0.0
Author: e-mot
Text Domain: simple-contact-form
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
        <button type="submit">送信</button>
    </form>
    <div class="scf-message"></div>
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
        created DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

/**
 * Ensure the inquiries table exists. Can be called on-demand.
 */
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
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

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
});

function scf_admin_inquiry_settings_page() {
    // 保存処理
    if (isset($_POST['scf_settings_nonce']) && wp_verify_nonce($_POST['scf_settings_nonce'], 'scf_settings')) {
        $period = max(1, intval($_POST['scf_file_period']));
        $mail = sanitize_email($_POST['scf_support_mail']);
        update_option('scf_file_period', $period);
        update_option('scf_support_mail', $mail);
        echo '<div class="updated notice"><p>設定を保存しました。</p></div>';
    }
    $period = get_option('scf_file_period', 365);
    $mail = get_option('scf_support_mail', 'support@e-mot.co.jp');
    echo '<div class="wrap"><h1>お問い合わせ設定</h1>';
    echo '<form method="post">';
    wp_nonce_field('scf_settings', 'scf_settings_nonce');
    echo '<table class="form-table">';
    echo '<tr><th>ファイル保持期間</th><td><input type="number" name="scf_file_period" value="'.esc_attr($period).'" min="1" style="width:80px;"> 日</td></tr>';
    echo '<tr><th>サポートメールアドレス</th><td><input type="email" name="scf_support_mail" value="'.esc_attr($mail).'" style="width:300px;"></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="保存"></p>';
    echo '</form>';
    echo '</div>';
}

function scf_admin_inquiry_list_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    // attempt to create table if missing
    scf_create_table();
    echo '<div class="wrap"><h1>お問い合わせ管理</h1>';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        echo '<div class="notice notice-warning"><p>データベーステーブル ' . esc_html($table) . ' が存在しません。プラグインを再有効化してください。</p></div></div>';
        return;
    }
    // pagination
    $per_page = 100;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));
    $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
    $offset = ($paged - 1) * $per_page;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created DESC LIMIT %d OFFSET %d", $per_page, $offset));
    if (!$rows) {
        echo '<p>まだお問い合わせはありません。</p></div>';
        return;
    }
    // pagination links (above)
    if ( $total > $per_page ) {
        $base_url = admin_url('admin.php?page=scf_inquiry_list');
        $base = add_query_arg('paged', '%#%', $base_url);
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
            'base' => $base,
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'type' => 'list',
        ]) . '</div></div>';
    }
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th>日時</th><th>番号</th><th>お名前</th><th>メール</th><th>種別</th><th>内容（抜粋）</th><th>添付</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . esc_html($r->created) . '</td>';
        $view_url = admin_url('admin.php?page=scf_inquiry_view&inquiry_id=' . intval($r->id));
        echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($r->inquiry_no) . '</a></td>';
        echo '<td>' . esc_html($r->name) . '</td>';
        echo '<td><a href="mailto:' . esc_attr($r->email) . '">' . esc_html($r->email) . '</a></td>';
        echo '<td>' . esc_html($r->inquiry) . '</td>';
        echo '<td>' . nl2br(esc_html(mb_strimwidth($r->content,0,120,'...'))) . '</td>';
        // 添付
        $files = $r->files ? maybe_unserialize($r->files) : [];
        echo '<td>';
        if ($files && is_array($files)) {
            foreach ($files as $f) {
                if (strpos($f['mime'], 'image/') === 0) {
                    echo '<a href="' . esc_url($f['url']) . '" target="_blank"><img src="' . esc_url($f['thumb']) . '" style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin:2px;vertical-align:middle;"></a> ';
                } else {
                    echo '<a href="' . esc_url($f['url']) . '" target="_blank">' . esc_html($f['name']) . '</a><br>';
                }
            }
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    // pagination links (below)
    if ( $total > $per_page ) {
        $base_url = admin_url('admin.php?page=scf_inquiry_list');
        $base = add_query_arg('paged', '%#%', $base_url);
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
            'base' => $base,
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'type' => 'list',
        ]) . '</div></div>';
    }
}

function scf_admin_inquiry_view_page() {
    if ( empty($_GET['inquiry_id']) ) {
        echo '<div class="wrap"><p>無効な問い合わせIDです。</p></div>';
        return;
    }
    $id = intval($_GET['inquiry_id']);
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    if ( ! $row ) {
        echo '<div class="wrap"><p>該当するお問い合わせが見つかりません。</p></div>';
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>お問い合わせ詳細 — ' . esc_html($row->inquiry_no) . '</h1>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=scf_inquiry_list')) . '">&laquo; 一覧に戻る</a></p>';
    echo '<table class="form-table">';
    $fields = [
        '日時' => $row->created,
        '番号' => $row->inquiry_no,
        'お名前' => $row->name,
        'メール' => '<a href="mailto:' . esc_attr($row->email) . '">' . esc_html($row->email) . '</a>',
        '郵便番号' => $row->zip,
        '住所' => $row->address,
        '電話番号' => $row->tel,
        '種別' => $row->inquiry,
        '商品名' => $row->product,
        'お買い上げ日' => $row->date,
        'ご購入店舗' => $row->shop,
        '内容' => nl2br(esc_html($row->content)),
    ];
    foreach ($fields as $k => $v) {
        echo '<tr><th style="width:18%;text-align:left;">' . esc_html($k) . '</th><td>' . $v . '</td></tr>';
    }
    echo '</table>';
    // attachments
    $files = $row->files ? maybe_unserialize($row->files) : [];
    if ($files && is_array($files)) {
        echo '<h2>添付ファイル</h2><div>';
        foreach ($files as $f) {
            if (strpos($f['mime'], 'image/') === 0) {
                echo '<a href="' . esc_url($f['url']) . '" target="_blank"><img src="' . esc_url($f['thumb']) . '" style="max-width:200px;margin:6px;border:1px solid #ddd;padding:4px;background:#fff;"></a>';
            } else {
                echo '<p><a href="' . esc_url($f['url']) . '" target="_blank">' . esc_html($f['name']) . '</a></p>';
            }
        }
        echo '</div>';
    }
    echo '</div>';
}
// ファイル保持期間に応じて添付ファイルを自動削除
add_action('scf_delete_old_attachments', function() {
    $period = intval(get_option('scf_file_period', 365));
    $before = $period > 0 ? $period . ' days ago' : '365 days ago';
    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'date_query' => [
            [
                'before' => $before,
                'column' => 'post_date_gmt',
            ],
        ],
        'meta_query' => [
            [
                'key' => '_scf_uploaded',
                'value' => '1',
            ],
        ],
        'fields' => 'ids',
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

    // Ensure table exists before insert
    scf_create_table();
    // DB保存
        global $wpdb;
        $table = $wpdb->prefix . 'scf_inquiries';
        $wpdb->insert($table, [
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
        ]);
        // Diagnostic logging: record insert id and any DB error
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('[scf] inserted id=' . intval($wpdb->insert_id));
            if ( $wpdb->last_error ) {
                error_log('[scf] db error: ' . $wpdb->last_error);
            }
        }
        // 管理者宛メール
    $to = get_option('scf_support_mail', 'support@e-mot.co.jp');
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
        $sent = wp_mail($to, $subject, $body, $headers);

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
            $msg = '送信が完了しました。\nお問い合わせ番号: '.$inquiry_no.'\nご入力いただいたメールアドレス宛に控えを送信しました。';
            $msg = nl2br($msg);
            wp_send_json_success(['message' => $msg]);
        } else {
            wp_send_json_error(['message' => '送信に失敗しました。']);
        }
        exit;
    }
});

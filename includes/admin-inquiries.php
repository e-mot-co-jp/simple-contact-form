<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('scf_admin_inquiry_list_page')) {
function scf_admin_inquiry_list_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    // attempt to create/upgrade table if missing
    if (function_exists('scf_create_table')) scf_create_table();
    if (function_exists('scf_upgrade_table_schema')) scf_upgrade_table_schema();

    echo '<div class="wrap"><h1>お問い合わせ管理</h1>';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        echo '<div class="notice notice-warning"><p>データベーステーブル ' . esc_html($table) . ' が存在しません。プラグインを再有効化してください。</p></div></div>';
        return;
    }
    // filters
    $filter_q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $filter_kw = isset($_GET['kw']) ? sanitize_text_field($_GET['kw']) : '';
    $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
    $filter_from = isset($_GET['filter_from']) ? sanitize_text_field($_GET['filter_from']) : '';
    $filter_to = isset($_GET['filter_to']) ? sanitize_text_field($_GET['filter_to']) : '';
    $per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 100;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

    $where = [];
    $params = [];
    if ($filter_q !== '') { $where[] = ' inquiry_no LIKE %s '; $params[] = '%' . $wpdb->esc_like($filter_q) . '%'; }
    elseif ($filter_kw !== '') {
        $like = '%' . $wpdb->esc_like($filter_kw) . '%';
        $where[] = "( name LIKE %s OR email LIKE %s OR content LIKE %s OR product LIKE %s OR shop LIKE %s )";
        $params = array_merge($params, [$like,$like,$like,$like,$like]);
    }
    if ($filter_type !== '') { $where[] = ' inquiry = %s '; $params[] = $filter_type; }
    if ($filter_from !== '') { $where[] = ' created >= %s '; $params[] = $filter_from . ' 00:00:00'; }
    if ($filter_to !== '') { $where[] = ' created <= %s '; $params[] = $filter_to . ' 23:59:59'; }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count_sql = "SELECT COUNT(*) FROM $table " . $where_sql;
    $total = $params ? intval($wpdb->get_var($wpdb->prepare($count_sql, $params))) : intval($wpdb->get_var($count_sql));
    $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
    $offset = ($paged - 1) * $per_page;

    $select_sql = "SELECT * FROM $table " . $where_sql . " ORDER BY created DESC LIMIT %d OFFSET %d";
    if ($params) { $rows = $wpdb->get_results(call_user_func_array([$wpdb,'prepare'], array_merge([$select_sql], array_merge($params, [$per_page,$offset])))); }
    else { $rows = $wpdb->get_results($wpdb->prepare($select_sql, $per_page, $offset)); }

    echo '<form method="get" class="scf-filter" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="scf_inquiry_list">';
    echo '<label style="margin-right:8px;">お問い合わせ番号: <input type="search" name="q" value="' . esc_attr($filter_q) . '" placeholder="例: 240930-ABCD"></label>';
    echo '<label style="margin-right:8px;">フリーワード: <input type="search" name="kw" value="' . esc_attr($filter_kw) . '" placeholder="名前・メール・内容・商品名・店舗"></label>';
    echo '<label style="margin-right:8px;">種別: <select name="filter_type">';
    echo '<option value="">-- 全て --</option>';
    $types = ['保証内容について','オンラインショップについて','製品の仕様などについて','リコールについて','その他'];
    foreach ($types as $t) { echo '<option value="' . esc_attr($t) . '"' . selected($filter_type, $t, false) . '>' . esc_html($t) . '</option>'; }
    echo '</select></label>';
    echo '<label style="margin-right:8px;">日付 from: <input type="date" name="filter_from" value="' . esc_attr($filter_from) . '"></label>';
    echo '<label style="margin-right:8px;">to: <input type="date" name="filter_to" value="' . esc_attr($filter_to) . '"></label>';
    echo '<label style="margin-right:8px;">表示件数: <select name="per_page">';
    foreach ([20,50,100,200] as $opt) { echo '<option value="' . intval($opt) . '"' . selected($per_page, $opt, false) . '>' . intval($opt) . '</option>'; }
    echo '</select></label>';
    echo '<input type="submit" class="button" value="絞り込む">';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=scf_inquiry_list')) . '">リセット</a>';
    echo '</form>';

    if (!$rows) { echo '<p>まだお問い合わせはありません。</p></div>'; return; }

    if ($total > $per_page) {
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
    echo '<th>日時</th><th>番号</th><th>お名前</th><th>メール</th><th>種別</th><th>内容（抜粋）</th><th>添付</th><th>判定</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $row_class = (isset($r->is_spam) && $r->is_spam) ? ' class="scf-row-spam"' : '';
        echo '<tr'.$row_class.'>';
        echo '<td>' . esc_html($r->created) . '</td>';
        $view_url = admin_url('admin.php?page=scf_inquiry_view&inquiry_id=' . intval($r->id));
        echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($r->inquiry_no) . '</a></td>';
        echo '<td>' . esc_html($r->name) . '</td>';
        echo '<td><a href="mailto:' . esc_attr($r->email) . '">' . esc_html($r->email) . '</a></td>';
        echo '<td>' . esc_html($r->inquiry) . '</td>';
        echo '<td>' . nl2br(esc_html(mb_strimwidth($r->content,0,120,'...'))) . '</td>';
        $files = $r->files ? maybe_unserialize($r->files) : [];
        echo '<td>';
        if ($files && is_array($files)) {
            foreach ($files as $f) {
                if (!empty($f['mime']) && strpos($f['mime'], 'image/') === 0) {
                    echo '<a href="' . esc_url($f['url']) . '" target="_blank"><img src="' . esc_url($f['thumb']) . '" style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin:2px;vertical-align:middle;"></a> ';
                } else {
                    echo '<a href="' . esc_url($f['url']) . '" target="_blank">' . esc_html($f['name']) . '</a><br>';
                }
            }
        }
        echo '</td>';
        echo '<td>';
        if ( isset($r->is_spam) && $r->is_spam ) {
            echo '<span style="color:#fff;background:#d9534f;padding:4px 8px;border-radius:12px;display:inline-block;">SPAM</span>';
        } else {
            echo '<span style="color:#fff;background:#5cb85c;padding:4px 8px;border-radius:12px;display:inline-block;">OK</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    if ($total > $per_page) {
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
}

if (!function_exists('scf_admin_inquiry_view_page')) {
function scf_admin_inquiry_view_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'scf_inquiries';
    $id = isset($_GET['inquiry_id']) ? intval($_GET['inquiry_id']) : 0;
    echo '<div class="wrap"><h1>お問い合わせ詳細</h1>';
    if (!$id) { echo '<p>不正なIDです。</p></div>'; return; }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    if (!$row) { echo '<p>データが見つかりません。</p></div>'; return; }

    echo '<table class="widefat fixed striped" style="max-width:900px">';
    $fields = [
        '日時' => $row->created,
        '番号' => $row->inquiry_no,
        'お名前' => $row->name,
        'メール' => $row->email,
        '郵便番号' => $row->zip,
        '住所' => $row->address,
        '電話番号' => $row->tel,
        '種別' => $row->inquiry,
        '商品名' => $row->product,
        'お買い上げ日' => $row->date,
        'ご購入店舗名' => $row->shop,
        '判定' => (isset($row->is_spam) && $row->is_spam) ? 'SPAM' : 'OK',
        '判定エンジン' => $row->spam_engine,
        '判定メモ' => $row->spam_note,
        '内容' => $row->content,
    ];
    foreach ($fields as $label => $val) {
        echo '<tr><th style="width:180px;">' . esc_html($label) . '</th><td>' . nl2br(esc_html($val)) . '</td></tr>';
    }
    // 添付
    $files = $row->files ? maybe_unserialize($row->files) : [];
    echo '<tr><th>添付</th><td>';
    if ($files && is_array($files)) {
        foreach ($files as $f) {
            $name = isset($f['name']) ? $f['name'] : basename($f['url']);
            echo '<div style="margin:4px 0"><a href="' . esc_url($f['url']) . '" target="_blank">' . esc_html($name) . '</a></div>';
        }
    } else {
        echo '-';
    }
    echo '</td></tr>';
    echo '</table>';
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=scf_inquiry_list')) . '">一覧へ戻る</a></p>';
    echo '</div>';
}
}

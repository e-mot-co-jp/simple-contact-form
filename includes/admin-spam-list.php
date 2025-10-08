<?php
if (!defined('ABSPATH')) exit;

function scf_admin_spam_list_page() {
    global $wpdb;
    $table = 'spam_list';
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") != $table ) {
        echo '<div class="wrap"><h1>spam_list管理</h1><p>spam_listテーブルが存在しません。</p></div>';
        return;
    }
    if ( isset($_POST['scf_spamlist_update']) && isset($_POST['spam_id']) && isset($_POST['class']) && check_admin_referer('scf_spamlist_update') ) {
        $id = intval($_POST['spam_id']);
        $class = ($_POST['class'] === 'spam') ? 'spam' : 'ham';
        $wpdb->update($table, ['class' => $class], ['id' => $id]);
        echo '<div class="updated notice"><p>ID '.intval($id).' のclassを '.esc_html($class).' に更新しました。</p></div>';
    }
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    echo '<div class="wrap"><h1>spam_list管理</h1>';
    echo '<table class="widefat fixed striped"><thead><tr><th>ID</th><th>inquiry_id</th><th>class</th><th>message</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>'.intval($r->id).'</td>';
        echo '<td>'.intval($r->inquiry_id).'</td>';
        echo '<td>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('scf_spamlist_update');
        echo '<input type="hidden" name="spam_id" value="'.intval($r->id).'">';
        echo '<select name="class">';
        echo '<option value="ham"'.($r->class==='ham'?' selected':'').'>ham</option>';
        echo '<option value="spam"'.($r->class==='spam'?' selected':'').'>spam</option>';
        echo '</select> ';
        echo '<button type="submit" name="scf_spamlist_update" class="button">更新</button>';
        echo '</form>';
        echo '</td>';
        echo '<td style="max-width:480px;overflow:auto;">'.esc_html(mb_strimwidth($r->message,0,300,'...')).'</td>';
        $view_url = admin_url('admin.php?page=scf_inquiry_view&inquiry_id='.intval($r->inquiry_id));
        echo '<td><a href="'.$view_url.'">問合せ詳細</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

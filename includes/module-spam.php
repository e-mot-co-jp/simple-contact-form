<?php
if (!defined('ABSPATH')) exit;

/**
 * spam_listテーブルのスキーマ自動アップグレード（createdカラム追加）
 */
function scf_upgrade_spam_list_schema() {
    global $wpdb;
    $table = 'spam_list';
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") != $table ) return;
    $cols = $wpdb->get_results("DESCRIBE $table");
    if( ! $cols ) return;
    $have = [];
    foreach($cols as $c){ $have[$c->Field] = true; }
    $alters = [];
    if( empty($have['created']) ) $alters[] = 'ADD created DATETIME DEFAULT CURRENT_TIMESTAMP AFTER message';
    if( $alters ){
        $sql = 'ALTER TABLE '.$table.' '.implode(', ', $alters);
        $wpdb->query($sql);
        if( defined('WP_DEBUG') && WP_DEBUG ) error_log('[scf] spam_list schema upgraded: '.$sql.' error='.$wpdb->last_error);
    }
}

/**
 * spam_listテーブルの自動作成（id, inquiry_id, class, message, created）
 */
function scf_create_spam_list_table() {
    global $wpdb;
    $table = 'spam_list';
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") != $table ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id BIGINT UNSIGNED,
            class VARCHAR(16) DEFAULT 'ham',
            message TEXT,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

/**
 * Simple spam check. Returns array: [is_spam(bool), engine(string), note(string)].
 */
function scf_check_spam($text) {
    $text = mb_strtolower(trim($text));
    $keywords = [ '営業','販売','セール','セールス','見積','ご提案','ご案内','促進','勧誘','販促','お問い合わせ（営業）' ];
    foreach ($keywords as $kw) {
        if (mb_strpos($text, mb_strtolower($kw)) !== false) {
            return [true, 'keyword', 'matched: ' . $kw];
        }
    }
    if ( get_option('scf_use_python_spam', false) ) {
        $py_path = trim(get_option('scf_python_path', 'python3'));
        $script = plugin_dir_path(__FILE__) . '../sales_block.py';
        if ($py_path !== '' && strpos($py_path, '~') === 0) {
            $home = getenv('HOME');
            if (!$home && !empty($_SERVER['HOME'])) $home = $_SERVER['HOME'];
            if ($home) $py_path = $home . substr($py_path, 1);
        }
        $resolved = '';
        if ($py_path === '') {
            $resolved = '';
        } elseif (strpos($py_path, '/') === false) {
            $which = trim(@shell_exec('which ' . escapeshellarg($py_path) . ' 2>/dev/null'));
            if ($which) $resolved = $which;
        } else {
            if (is_executable($py_path)) $resolved = $py_path;
            elseif (file_exists($py_path)) $resolved = $py_path;
        }
        if ($resolved) {
            $cmd = escapeshellcmd($resolved) . ' ' . escapeshellarg($script);
            $descriptors = [ [ 'pipe','r' ], [ 'pipe','w' ], [ 'pipe','w' ] ];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $text . "\n"); fclose($pipes[0]);
                stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
                $output = ''; $errout = ''; $start = microtime(true); $timeout = 3.0;
                while (true) {
                    $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
                    $num = @stream_select($read, $write, $except, 0, 200000);
                    if ($num === false) break;
                    if ($num > 0) {
                        foreach ($read as $r) { $buf = stream_get_contents($r); if ($r === $pipes[1]) $output .= $buf; else $errout .= $buf; }
                    }
                    $status = proc_get_status($proc);
                    if (!$status['running']) break;
                    if ((microtime(true) - $start) > $timeout) { @proc_terminate($proc); break; }
                    usleep(100000);
                }
                $output .= stream_get_contents($pipes[1]); $errout .= stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $code = proc_close($proc);
                $out = trim($output);
                if ($out === 'spam') return [true, 'python', 'python:spam'];
                elseif ($out === 'ham') return [false, 'python', 'python:ham'];
            }
        }
    }
    return [false, 'none', 'no match'];
}

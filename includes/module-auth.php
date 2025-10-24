<?php
if (!defined('ABSPATH')) exit;

// Redirect logged-in users away from auth pages
if (!function_exists('scf_redirect_logged_in_from_auth_pages')) {
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
}

// Turnstile helpers
if (!function_exists('scf_get_turnstile_sitekey')){
function scf_get_turnstile_sitekey() { if (!get_option('scf_turnstile_enabled', 0)) return ''; return trim(get_option('scf_turnstile_sitekey', '')); }
}
if (!function_exists('scf_get_turnstile_secret')){
function scf_get_turnstile_secret() { if (!get_option('scf_turnstile_enabled', 0)) return ''; return trim(get_option('scf_turnstile_secret', '')); }
}

if (!function_exists('scf_turnstile_verify')){
function scf_turnstile_verify($token, $context = 'generic'){ 
    $secret = scf_get_turnstile_secret();
    if (!$secret) return [true, ['skipped' => 'disabled']];
    $token = trim($token);
    if ($token === '') return [false, ['error' => 'empty-token']];
    $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [ 'timeout' => 15, 'body' => [ 'secret' => $secret, 'response' => $token ] ]);
    if (is_wp_error($resp)) { if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[SCF TurnstileDBG] context='.$context.' http_error msg='.$resp->get_error_message().' token_len='.strlen($token)); } return [false, ['wp_error' => $resp->get_error_message()]]; }
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
}

// Social login rendering (Nextend)
if (!function_exists('scf_render_social_login_block')){
function scf_render_social_login_block() {
    if (shortcode_exists('nextend_social_login')) {
        return '<div class="scf-nextend-social-login">' . do_shortcode('[nextend_social_login]') . '</div>';
    }
    return '';
}
}

// Login form shortcode
if (!function_exists('scf_login_form_shortcode')){
function scf_login_form_shortcode($atts) {
    if (is_user_logged_in()) { return '<p>' . esc_html__('ログイン中です。', 'simple-contact-form') . '</p>'; }
    $redirect = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : ( function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/') );
    $failed = ( isset($_GET['scf_login']) && $_GET['scf_login'] === 'failed' );
    $attempt_user = isset($_GET['u']) ? sanitize_user(wp_unslash($_GET['u']), true) : '';
    $args = [ 'redirect' => $redirect, 'label_username' => __('Eメールまたはユーザー名', 'simple-contact-form'), 'label_log_in' => __('ログイン', 'simple-contact-form'), 'value_username' => $attempt_user ];
    $hidden_cb = function($content, $cb_args){ return $content . '<input type="hidden" name="scf_custom_login" value="1" />'; };
    add_filter('login_form_middle', $hidden_cb, 10, 2);
    ob_start();
    if ($failed) { echo '<div class="scf-login-errors"><p class="scf-error" style="color:#b32d2e;">' . esc_html__('ユーザー名またはパスワードが正しくありません。', 'simple-contact-form') . '</p></div>'; }
    wp_login_form($args);
    remove_filter('login_form_middle', $hidden_cb, 10);
    // Turnstile有効時のみボタン制御用スクリプト追加（ウィジェットは自動挿入に任せる）
    $sitekey = scf_get_turnstile_sitekey();
    if ($sitekey) {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.querySelector('form.login');
            if(form){
                var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                if(btn) btn.disabled = true;
            }
        });
        window.turnstileWPCallback = function(){
            var form = document.querySelector('form.login');
            if(form){
                var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                if(btn) {
                    btn.disabled = false;
                    btn.style.pointerEvents = '';
                    btn.style.opacity = '';
                }
            }
        };
        </script>
        <?php
    }
    return ob_get_clean();
}
add_shortcode('scf_login_form', 'scf_login_form_shortcode');
add_shortcode('mot_login_form', 'scf_login_form_shortcode');
}

// Login failed redirect
if (!function_exists('scf_login_failed_redirect')){
function scf_login_failed_redirect($username){
    if (empty($_POST) || !isset($_POST['scf_custom_login'])) { return; }
    $redirect_to = '';
    if (isset($_POST['redirect_to'])) { $candidate = esc_url_raw(wp_unslash($_POST['redirect_to'])); $validated = wp_validate_redirect($candidate, ''); if ($validated) $redirect_to = $validated; }
    $params = [ 'scf_login' => 'failed' ]; if ($username) { $params['u'] = rawurlencode($username); } if ($redirect_to) { $params['redirect_to'] = rawurlencode($redirect_to); }
    $login_page = home_url('/login/'); $url = add_query_arg($params, $login_page);
    if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[SCF LoginDBG] failed login for '.$username.' redirecting to '.$url); }
    wp_safe_redirect($url); exit;
}
add_action('wp_login_failed', 'scf_login_failed_redirect', 1, 1);
}

// Registration form shortcode
if (!function_exists('scf_register_form_shortcode')){
function scf_register_form_shortcode($atts) {
    if (is_user_logged_in()) { return '<p>' . esc_html__('ログイン中です。', 'simple-contact-form') . '</p>'; }
    $errors = [];
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['scf_register_nonce']) && wp_verify_nonce($_POST['scf_register_nonce'], 'scf_register')) {
        if ( ! defined('SCF_REGISTER_FORM_SUBMISSION') ) { define('SCF_REGISTER_FORM_SUBMISSION', true); }
        $email = isset($_POST['scf_email']) ? sanitize_email($_POST['scf_email']) : '';
        $username = isset($_POST['scf_username']) ? sanitize_user($_POST['scf_username']) : '';
        $password = isset($_POST['scf_password']) ? $_POST['scf_password'] : '';
        $password_confirm = isset($_POST['scf_password_confirm']) ? $_POST['scf_password_confirm'] : '';
        if (empty($email) || !is_email($email)) $errors[] = __('有効なメールアドレスを入力してください。', 'simple-contact-form');
        if (empty($password)) { $errors[] = __('パスワードを入力してください。', 'simple-contact-form'); }
        else {
            if (strlen($password) < 8) $errors[] = __('パスワードは8文字以上必要です。', 'simple-contact-form');
            if (!preg_match('/[A-Z]/', $password)) $errors[] = __('パスワードには英大文字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/[a-z]/', $password)) $errors[] = __('パスワードには英小文字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/\d/', $password)) $errors[] = __('パスワードには数字が少なくとも1文字必要です。', 'simple-contact-form');
            if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = __('パスワードには記号が少なくとも1文字必要です。', 'simple-contact-form');
            if (preg_match('/\s/', $password)) $errors[] = __('パスワードに空白文字は使用できません。', 'simple-contact-form');
            $score = 0; $classes = 0;
            if (preg_match('/[A-Z]/',$password)) $classes++;
            if (preg_match('/[a-z]/',$password)) $classes++;
            if (preg_match('/\d/',$password)) $classes++;
            if (preg_match('/[^A-Za-z0-9]/',$password)) $classes++;
            if ($classes >= 3 && strlen($password) >= 12) $score = 4;
            elseif ($classes >= 3 && strlen($password) >= 10) $score = 3;
            elseif ($classes >= 2 && strlen($password) >= 8) $score = 2;
            elseif ($classes >= 2) $score = 1; else $score = 0;
            $required_score = intval(get_option('scf_pw_strength_min', 0));
            if ($required_score > 0 && $score < $required_score) { $errors[] = sprintf(__('パスワード強度が不足しています。(必要:%d / 現在:%d)', 'simple-contact-form'), $required_score, $score); }
        }
        if ($password !== $password_confirm) $errors[] = __('パスワード（確認）が一致しません。', 'simple-contact-form');
        if (empty($username) && $email) { $username = sanitize_user(current(explode('@', $email)), true); }
        $secret = scf_get_turnstile_secret(); $scf_turnstile_verified = false;
        if ($secret) {
            $token = ''; foreach (['cf-turnstile-response','cf_turnstile_response','turnstile-response'] as $k) { if (!empty($_POST[$k])) { $token = sanitize_text_field($_POST[$k]); break; } }
            if (!$token) { $errors[] = __('Turnstile の検証トークンが見つかりませんでした。', 'simple-contact-form'); }
            else { list($ok, $raw) = scf_turnstile_verify($token, 'register'); if (!$ok) {
                    $codes = []; if (isset($raw['error-codes'])) { $codes = (array)$raw['error-codes']; }
                    $msg = __('Turnstile の検証に失敗しました。', 'simple-contact-form');
                    if ($codes) { if (in_array('timeout-or-duplicate', $codes, true)) $msg = __('Turnstile がタイムアウトまたは重複しました。ページを再読み込みし、再度チェックしてください。', 'simple-contact-form');
                        elseif (in_array('invalid-input-secret', $codes, true)) $msg = __('Turnstile シークレットキーが無効です。管理者に連絡してください。', 'simple-contact-form');
                        elseif (in_array('invalid-input-response', $codes, true)) $msg = __('Turnstile 応答トークンが無効です。再度チェックしてください。', 'simple-contact-form'); }
                    $errors[] = $msg; }
                if ($ok) { $scf_turnstile_verified = true; $GLOBALS['scf_turnstile_verified'] = true; }
            }
        }
        if (empty($errors)) {
            if (function_exists('wc_create_new_customer')) {
                $user_id = wc_create_new_customer($email, $username, $password);
                if (is_wp_error($user_id)) { $errors[] = $user_id->get_error_message(); }
                else {
                    wp_set_current_user($user_id); wp_set_auth_cookie($user_id); $scf_user_obj = get_user_by('id', $user_id);
                    do_action('wp_login', $username, $scf_user_obj);
                    wp_safe_redirect(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')); exit;
                }
            } else { $errors[] = __('WooCommerce functions not available.', 'simple-contact-form'); }
        }
    }
    ob_start();
    if (!empty($errors)) {
        echo '<div class="scf-register-errors">'; foreach ($errors as $e) echo '<p class="scf-error">' . esc_html($e) . '</p>'; echo '</div>';
    }
    $sitekey = scf_get_turnstile_sitekey(); $required_score = intval(get_option('scf_pw_strength_min', 0));
    ?>
    <form method="post" class="scf-register-form">
        <?php wp_nonce_field('scf_register', 'scf_register_nonce'); ?>
        <script>window.scfPwRequiredScore = <?php echo (int) $required_score; ?>;</script>
        <p><label for="scf_email"><?php esc_html_e('Eメール', 'simple-contact-form'); ?></label><br>
        <input type="email" name="scf_email" id="scf_email" required value="<?php echo esc_attr(isset($_POST['scf_email']) ? $_POST['scf_email'] : ''); ?>"></p>
        <p><label for="scf_username"><?php esc_html_e('ユーザー名 (任意)', 'simple-contact-form'); ?></label><br>
        <input type="text" name="scf_username" id="scf_username" value="<?php echo esc_attr(isset($_POST['scf_username']) ? $_POST['scf_username'] : ''); ?>"></p>
        <p><label for="scf_password"><?php esc_html_e('パスワード', 'simple-contact-form'); ?></label><br>
        <input type="password" name="scf_password" id="scf_password" required></p>
        <p><label for="scf_password_confirm"><?php esc_html_e('パスワード（確認）', 'simple-contact-form'); ?></label><br>
        <input type="password" name="scf_password_confirm" id="scf_password_confirm" required></p>
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
            window.scfRegTurnstileSuccess = function(){};
            window.scfRegTurnstileExpired = function(){ var f=document.querySelector('input[name="cf-turnstile-response"]'); if(f){ f.value=''; } };
            document.addEventListener('submit', function(e){ var form=e.target; if(!form.classList||!form.classList.contains('scf-register-form')) return; var field=form.querySelector('input[name="cf-turnstile-response"]'); if(!field||!field.value){ e.preventDefault(); alert('セキュリティ確認（Turnstile）を完了してください。'); } }, true);
            </script>
        <?php endif; ?>
        <p><button type="submit"><?php esc_html_e('登録', 'simple-contact-form'); ?></button></p>
    </form>
    <?php echo scf_render_social_login_block(); ?>
    <?php
    return ob_get_clean();
}
add_shortcode('scf_register_form', 'scf_register_form_shortcode');
add_shortcode('mot_register_form', 'scf_register_form_shortcode');
}

// Redirect My Account to /login/
if (!function_exists('scf_redirect_myaccount_to_custom_login')){
function scf_redirect_myaccount_to_custom_login(){
    if ( function_exists('is_account_page') && is_account_page() && ! is_user_logged_in() ) {
        $login_slug = '/login/'; $register_slug = '/register/';
        $request = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($request, $login_slug) !== false || strpos($request, $register_slug) !== false ) { return; }
        $login_page = home_url($login_slug);
        $redirect_to = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $login_page);
        wp_safe_redirect($url); exit;
    }
}
add_action('template_redirect', 'scf_redirect_myaccount_to_custom_login', 5);
}

// Nextend Social blocks & styles
if (!function_exists('scf_print_nextend_social_login')){
function scf_print_nextend_social_login(){ if (shortcode_exists('nextend_social_login')) { echo '<div class="scf-nextend-social-login">' . do_shortcode('[nextend_social_login]') . '</div>'; } }
add_action('woocommerce_login_form_end', 'scf_print_nextend_social_login');
add_action('woocommerce_checkout_login_form', 'scf_print_nextend_social_login');
}

if (!function_exists('scf_nextend_styles')){
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
}

// SiteGuard CAPTCHA injection
if (!function_exists('scf_siteguard_captcha_login')){
function scf_siteguard_captcha_login(){
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) { return; }
    global $siteguard_captcha, $siteguard_config;
    if ( isset( $siteguard_captcha ) && is_object( $siteguard_captcha )
        && method_exists( $siteguard_captcha, 'handler_login_form' )
        && isset( $siteguard_config )
        && '1' === $siteguard_config->get( 'captcha_enable' )
        && '0' !== $siteguard_config->get( 'captcha_login' ) ) {
        ob_start(); $siteguard_captcha->handler_login_form(); echo ob_get_clean();
    }
}
add_action('woocommerce_login_form', 'scf_siteguard_captcha_login', 20);
}

// Force customer role for social-linked users
if (!function_exists('scf_force_customer_role_for_social')){
function scf_force_customer_role_for_social($user_id){
    if ( is_admin() ) return;
    if ( ! function_exists('wc_get_page_permalink') ) return;
    if ( ! get_role('customer') ) return;
    $user = get_userdata($user_id); if ( ! $user ) return;
    if ( in_array('customer', (array)$user->roles, true) ) return;
    if ( count((array)$user->roles) !== 1 || ! in_array('subscriber', (array)$user->roles, true) ) return;
    $is_social = false;
    foreach (['nsl_provider','nsl_nonce','nsl_auth','provider'] as $k){ if ( isset($_REQUEST[$k]) && $_REQUEST[$k] !== '' ){ $is_social = true; break; } }
    if ( ! $is_social && ! empty($_SERVER['HTTP_REFERER']) ) { $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH); if ( $ref_path && preg_match('#/(login|register|my-account)#', $ref_path) ) { $is_social = true; } }
    if ( ! $is_social ) { foreach ($_COOKIE as $ckey => $cval){ if ( stripos($ckey, 'nsl_') === 0 ){ $is_social = true; break; } } }
    if ( ! $is_social ) return;
    $wp_user = new WP_User($user_id); $wp_user->set_role('customer');
    do_action('scf_user_promoted_to_customer_from_social', $user_id);
}
add_action('user_register','scf_force_customer_role_for_social', 200);
}

// Remove duplicate Turnstile error after verified in our form
if (!function_exists('scf_filter_wc_errors_remove_cfturnstile')){
function scf_filter_wc_errors_remove_cfturnstile($errors){
    if ( empty($errors) || ! is_object($errors) ) return $errors;
    if ( empty($GLOBALS['scf_turnstile_verified']) || ! defined('SCF_REGISTER_FORM_SUBMISSION') ) return $errors;
    if ( method_exists($errors, 'get_error_codes') ) {
        $codes = $errors->get_error_codes();
        if ( in_array('cfturnstile_error', $codes, true) ) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[SCF TurnstileDBG] removing cfturnstile_error (duplicate validation)'); }
            $errors->remove('cfturnstile_error');
        }
    }
    return $errors;
}
add_filter('woocommerce_registration_errors','scf_filter_wc_errors_remove_cfturnstile', 5, 1);
}

// Nextend events logging
add_action('nsl_user_connected_provider', function($provider,$user_id,$profile){ if (defined('WP_DEBUG') && WP_DEBUG) error_log('[SCF SocialLink] connected provider='.$provider.' user_id='.$user_id); }, 10, 3);
add_action('nsl_user_unlinked_provider', function($provider,$user_id){ if (defined('WP_DEBUG') && WP_DEBUG) error_log('[SCF SocialLink] disconnected provider='.$provider.' user_id='.$user_id); }, 10, 2);

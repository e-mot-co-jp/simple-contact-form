<?php
if (!defined('ABSPATH')) exit;

// Shortcode renderer for the contact form
if (!function_exists('scf_render_form')) {
function scf_render_form($atts = []) {
    $plugin_main = dirname(__FILE__) . '/../simple-contact-form.php';
    ob_start();
    $pdf_icon = plugins_url('pdf-icon.png', $plugin_main);
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
}

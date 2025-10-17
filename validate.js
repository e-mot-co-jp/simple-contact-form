// 入力 -> 確認 -> 送信 完了の二段階フロー実装
jQuery(function($){
  const FIELD_MAP = [
    {name:'scf_name', label:'お名前'},
    {name:'scf_email', label:'メールアドレス'},
    {name:'scf_email_confirm', label:'メールアドレス確認', hideOnConfirm:true},
    {name:'scf_zip', label:'郵便番号'},
    {name:'scf_address', label:'住所'},
    {name:'scf_tel', label:'電話番号'},
    {name:'scf_inquiry', label:'お問い合わせ種別'},
    {name:'scf_product', label:'商品名'},
    {name:'scf_date', label:'お買い上げ日'},
    {name:'scf_shop', label:'ご購入店舗名'},
    {name:'scf_content', label:'内容', isTextArea:true}
  ];
  const $form = $('.scf-form');
  if(!$form.length) return;
  const $confirm = $('.scf-confirm');
  const $confirmTable = $('.scf-confirm-table');
  const $complete = $('.scf-complete');
  const $message = $('.scf-message');
  let phase = 'input'; // input | confirm | sending | complete

  function validate(){
    let msg = '';
    const email = $form.find('[name="scf_email"]').val();
    const email2 = $form.find('[name="scf_email_confirm"]').val();
    if(email !== email2){ msg += 'メールアドレスが一致しません。\n'; }
    $form.find('[required]').each(function(){
      if(!$(this).val()){
        const label = ($(this).closest('label').text()||'').replace(/\*/g,'').replace(/\s+/g,' ').trim();
        msg += label + 'は必須です。\n';
      }
    });
    if(msg){
      showModal(msg.replace(/\n/g,'<br>'), true);
      return false;
    }
    return true;
  }

  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, function(s){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[s]);
    });
  }

  function buildConfirm(){
    const rows = [];
    rows.push('<table class="scf-confirm-table-inner" style="width:100%;border-collapse:collapse;">');
    FIELD_MAP.forEach(f => {
      if(f.hideOnConfirm) return; // 確認不要
      const el = $form.find('[name="'+f.name+'"]');
      if(!el.length) return;
      let val = el.val();
      if(f.name==='scf_inquiry'){ // select のテキスト
        val = el.find('option:selected').text();
      }
      if(f.isTextArea){ val = val.replace(/\n/g,'<br>'); }
      rows.push('<tr style="border-bottom:1px solid #eee;"><th style="text-align:left;padding:6px 8px;width:28%;background:#f7f7f7;vertical-align:top;font-weight:600;">'+escapeHtml(f.label)+'</th><td style="padding:6px 8px;">'+(val?val===' ' ? '&nbsp;' : val: '')+'</td></tr>');
    });
    // 添付ファイル
    const fileInput = $form.find('.scf-file-input')[0];
    if(fileInput && fileInput.files && fileInput.files.length){
      const names = Array.from(fileInput.files).map(f=>escapeHtml(f.name)).join('<br>');
      rows.push('<tr style="border-bottom:1px solid #eee;"><th style="text-align:left;padding:6px 8px;width:28%;background:#f7f7f7;vertical-align:top;font-weight:600;">添付ファイル</th><td style="padding:6px 8px;">'+names+'</td></tr>');
    }
    rows.push('</table>');
    $confirmTable.html(rows.join(''));
  }

  function switchToConfirm(){
    buildConfirm();
    phase = 'confirm';
    $form.hide();
    $confirm.show();
    $complete.hide();
    $message.text('').removeAttr('style');
  }

  function switchToInput(){
    phase = 'input';
    $form.show();
    $confirm.hide();
    $complete.hide();
    $message.text('').removeAttr('style');
  }

  function switchToComplete(msgHtml){
    phase = 'complete';
    $form.hide();
    $confirm.hide();
    $complete.show();
    $complete.find('.scf-complete-message').html(msgHtml||'送信が完了しました。');
    $message.text('').removeAttr('style');
  }

  // 入力フォーム submit (確認へ遷移)
  $form.on('submit', function(e){
    e.preventDefault();
    if(phase !== 'input') return false;
    if(!validate()) return false;
    switchToConfirm();
    return false;
  });

  // 戻る
  $confirm.on('click', '.scf-btn-back', function(){
    if(phase !== 'confirm') return;
    switchToInput();
  });

  // 送信
  $confirm.on('click', '.scf-btn-send', function(){
    if(phase !== 'confirm') return;
    phase = 'sending';
    const $btn = $(this).addClass('loading');
    $btn.data('original-text',$btn.text());
    $message.text('');
    const formData = new FormData($form[0]);
    formData.append('scf_ajax','1');
    $.ajax({
      url: location.href,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      success: function(res){
        if(res && res.success){
          const html = (res.data && res.data.message) ? res.data.message.replace(/\n/g,'<br>') : '送信が完了しました。';
          switchToComplete(html);
          // フォーム初期化
          $form[0].reset();
          $('.scf-file-list').empty();
          const inquiryId = (res.data && res.data.inquiry_id) ? res.data.inquiry_id : '';
          const modalMsg = inquiryId ? '送信が完了しました。<br>お問い合わせ番号: ' + inquiryId : '送信が完了しました。';
          showModal(modalMsg, false);
        } else {
          phase = 'confirm';
          const err = res && res.data && res.data.message ? res.data.message : '送信に失敗しました。';
          showModal(err.replace(/\n/g,'<br>'), true);
        }
        $('.scf-btn-send').removeClass('loading');
      },
      error: function(){
        phase = 'confirm';
        showModal('送信に失敗しました。', true);
        $('.scf-btn-send').removeClass('loading');
      }
    });
  });

  /* =====================
   * Modal Helpers
   * ===================== */
  const $modal = $('.scf-modal');
  const $modalBody = $modal.find('.scf-modal-body');
  function showModal(html, isError){
    $modalBody.html('<div class="'+(isError?'scf-modal-error':'scf-modal-info')+'">'+html+'</div>');
    $modal.css('display', 'flex').hide().fadeIn(120);
  }
  function closeModal(){
    $modal.fadeOut(120);
  }
  $modal.on('click','.scf-modal-close', closeModal);
  $modal.on('click','.scf-modal-overlay', closeModal);
  $(document).on('keydown', function(e){ if(e.key==='Escape' && $modal.is(':visible')) closeModal(); });
});

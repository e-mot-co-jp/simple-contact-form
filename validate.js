// jQueryバリデーション・送信処理
jQuery(function($){
  $('.scf-form').on('submit', function(e){
    e.preventDefault();
    var $form = $(this);
    var msg = '';
    var email = $form.find('[name="scf_email"]').val();
    var email2 = $form.find('[name="scf_email_confirm"]').val();
    if(email !== email2){
      msg += 'メールアドレスが一致しません。\n';
    }
    $form.find('[required]').each(function(){
      if(!$(this).val()){
        msg += $(this).closest('label').text().replace(/\*/g,'') + 'は必須です。\n';
      }
    });
    if(msg){
      $('.scf-message').text(msg).css('color','red');
      return false;
    }
    // Ajax送信（サンプル: 実際はPHP側で処理を追加）
    $.ajax({
      url: location.href,
      type: 'POST',
      data: $form.serialize() + '&scf_ajax=1',
      success: function(res){
        $('.scf-message').text('送信が完了しました。').css('color','green');
        $form[0].reset();
      },
      error: function(){
        $('.scf-message').text('送信に失敗しました。').css('color','red');
      }
    });
    return false;
  });
});

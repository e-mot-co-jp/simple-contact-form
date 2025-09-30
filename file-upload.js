// ファイル添付 ドラッグ＆ドロップ＋プレビュー＋バリデーション
jQuery(function($){
  var dropzone = $('.scf-dropzone');
  var fileInput = $('.scf-file-input');
  var fileList = $('.scf-file-list');
  var maxSize = 40 * 1024 * 1024;
  var allowed = ['jpg','jpeg','gif','pdf','heic','png'];

  // ドロップ/クリックでファイル選択
  dropzone.on('click keypress', function(e){
    if(e.type==='click' || e.key==='Enter') fileInput.trigger('click');
  });
  dropzone.on('dragover', function(e){
    e.preventDefault();
    dropzone.addClass('dragover');
  });
  dropzone.on('dragleave drop', function(e){
    e.preventDefault();
    dropzone.removeClass('dragover');
  });
  dropzone.on('drop', function(e){
    e.preventDefault();
    var files = e.originalEvent.dataTransfer.files;
    fileInput[0].files = files;
    showFiles(files);
  });
  fileInput.on('change', function(){
    showFiles(this.files);
  });
  function showFiles(files){
    fileList.empty();
    var ok = true;
    $.each(files, function(i, f){
      var ext = f.name.split('.').pop().toLowerCase();
      if($.inArray(ext, allowed)===-1){
        fileList.append('<div style="color:red">'+f.name+'：許可されていない形式</div>');
        ok = false;
      } else if(f.size > maxSize){
        fileList.append('<div style="color:red">'+f.name+'：40MB超過</div>');
        ok = false;
      } else {
        fileList.append('<div>'+f.name+' ('+Math.round(f.size/1024/1024*10)/10+'MB)</div>');
      }
    });
    if(!ok) fileInput.val('');
  }
  // 送信時にFormDataでファイルも送信
  $('.scf-form').off('submit').on('submit', function(e){
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
    var formData = new FormData($form[0]);
    formData.append('scf_ajax', '1');
    $.ajax({
      url: location.href,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      success: function(res){
        if(res.success){
          $('.scf-message').text(res.data.message).css('color','green');
          $form[0].reset();
          fileList.empty();
        }else{
          $('.scf-message').text(res.data.message).css('color','red');
        }
      },
      error: function(){
        $('.scf-message').text('送信に失敗しました。').css('color','red');
      }
    });
    return false;
  });
});

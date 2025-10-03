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
    var thumbList = $('<div class="scf-thumb-list"></div>');
    $.each(files, function(i, f){
      var ext = f.name.split('.').pop().toLowerCase();
      var item = $('<div class="scf-thumb-item"></div>');
      if($.inArray(ext, allowed)===-1){
        fileList.append('<div style="color:red">'+f.name+'：許可されていない形式</div>');
        ok = false;
      } else if(f.size > maxSize){
        fileList.append('<div style="color:red">'+f.name+'：40MB超過</div>');
        ok = false;
      } else {
        if(['jpg','jpeg','png','gif','heic'].indexOf(ext)!==-1){
          var reader = new FileReader();
          reader.onload = function(e){
            item.append('<img src="'+e.target.result+'" alt="thumb">');
            item.append('<div class="scf-thumb-label">'+f.name+'</div>');
          };
          reader.readAsDataURL(f);
        } else if(ext==='pdf') {
          item.append('<img src="'+(window.scfFileThumb ? window.scfFileThumb.pdfIcon : '')+'" alt="pdf" style="background:#fff;">');
          item.append('<div class="scf-thumb-label">'+f.name+'</div>');
        }
        thumbList.append(item);
      }
    });
    fileList.append(thumbList);
    if(!ok) fileInput.val('');
  }
// PDFアイコンパスをグローバル変数で渡す（PHP側でwindow.scfFileThumb.pdfIconを出力すること）
  // 送信時にFormDataでファイルも送信
  $('.scf-form').off('submit').on('submit', function(e){
    e.preventDefault();
    if(window.scfTwoStepFlow){
      // 二段階フロー有効時は validate.js が制御するためここで送信しない
      return false;
    }
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
          $('.scf-message').html(res.data.message.replace(/\n/g, '<br>')).css('color','green');
          $form[0].reset();
          fileList.empty();
        }else{
          $('.scf-message').html(res.data.message.replace(/\n/g, '<br>')).css('color','red');
        }
      },
      error: function(){
        $('.scf-message').text('送信に失敗しました。').css('color','red');
      }
    });
    return false;
  });
});

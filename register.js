(function($){
  var shown = false;
  var requiredScore = parseInt(window.scfPwRequiredScore || '0',10) || 0;
  function ensureShow(){
    if(!shown){
      $('.scf-password-helper').fadeIn(150);
      shown = true;
    }
  }
  function evaluate(){
    ensureShow();
    var p = $('#scf_password').val() || '';
    var c = $('#scf_password_confirm').val() || '';
    var rules = {
      length: p.length >= 8,
      upper: /[A-Z]/.test(p),
      lower: /[a-z]/.test(p),
      digit: /\d/.test(p),
      symbol: /[^A-Za-z0-9]/.test(p),
      match: p !== '' && p === c
    };
    Object.keys(rules).forEach(function(k){
      var li = $('.scf-password-policy [data-rule="'+k+'"]');
      if(!li.length) return;
      if(rules[k]){
        li.css({color:'#0a7a14',textDecoration:'line-through',opacity:0.7});
      }else{
        li.css({color:'#c00',textDecoration:'none',opacity:1});
      }
    });
    var score = updateStrength(p);
    enforceThreshold(score, rules);
  }

  function updateStrength(p){
    var $fill = $('.scf-strength-fill');
    var $text = $('.scf-strength-text');
    if(!$fill.length) return 0;
    if(!p){
      $fill.css({width:'0',background:'#d9534f'});
      if($text.length) $text.text('強度: -');
      return 0;
    }
    var score = 0;
    if(typeof zxcvbn === 'function'){
      try { score = zxcvbn(p).score; } catch(e){ score = 0; }
    } else {
      var s=0; if(/[A-Z]/.test(p)) s++; if(/[a-z]/.test(p)) s++; if(/\d/.test(p)) s++; if(/[^A-Za-z0-9]/.test(p)) s++; if(p.length>=12) s++; score = Math.min(4, Math.floor(s/5*4));
    }
    var widths = ['20%','40%','60%','80%','100%'];
    var colors = ['#d9534f','#f0ad4e','#f7e463','#5bc0de','#5cb85c'];
    var labels = ['とても弱い','弱い','やや弱い','普通','強い'];
    $fill.css({width: widths[score] || '20%', background: colors[score] || '#d9534f'});
    if($text.length) $text.text('強度: ' + (labels[score] || '-'));
    return score;
  }

  function enforceThreshold(score, rules){
    var $btn = $('.scf-register-form button[type="submit"]');
    var $msg = $('.scf-pw-threshold-msg');
    if(!$msg.length){
      $msg = $('<p class="scf-pw-threshold-msg" style="margin:6px 0 0;font-size:12px;color:#c00;display:none;"></p>').insertAfter('.scf-password-helper');
    }
    // 未達成要件を抽出し人間可読メッセージへ
    function unmetList(){
      var map = {
        length: '8文字以上',
        upper: '英大文字',
        lower: '英小文字',
        digit: '数字',
        symbol: '記号',
        match: '確認用と一致'
      };
      var arr = [];
      Object.keys(map).forEach(function(k){ if(!rules[k]) arr.push(map[k]); });
      if(!arr.length) return '';
      return ' 未達: ' + arr.join(' / ');
    }
    if(requiredScore > 0){
      if(score < requiredScore){
        $btn.prop('disabled', true).css({opacity:.6,cursor:'not-allowed'});
        var base = 'パスワード強度が不足しています (必要: '+requiredScore+' / 現在: '+score+')';
        var details = unmetList();
        $msg.text(base + details).show();
      } else if(!(rules.length && rules.upper && rules.lower && rules.digit && rules.symbol && rules.match)) {
        // 形式要件未達の場合も送信不可（明示メッセージ）
        $btn.prop('disabled', true).css({opacity:.6,cursor:'not-allowed'});
        $msg.text('パスワード要件をすべて満たしてください。' + unmetList()).show();
      } else {
        $btn.prop('disabled', false).css({opacity:1,cursor:'pointer'});
        $msg.hide();
      }
    } else {
      // 閾値無効時も形式要件満たすまで無効にするか迷うが現状は許可
      if(!(rules.length && rules.upper && rules.lower && rules.digit && rules.symbol && rules.match)) {
        $btn.prop('disabled', true).css({opacity:.6,cursor:'not-allowed'});
        $msg.text('パスワード要件をすべて満たしてください。' + unmetList()).show();
      } else {
        $btn.prop('disabled', false).css({opacity:1,cursor:'pointer'});
        $msg.hide();
      }
    }
  }

  $(document).on('focus','#scf_password,#scf_password_confirm', ensureShow);
  $(document).on('input','#scf_password,#scf_password_confirm', evaluate);
  $(function(){
    // サーバー埋め込みされた閾値取得用 hidden/meta 等があれば将来利用
    evaluate();
  });
})(jQuery);

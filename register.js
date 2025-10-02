(function($){
  function evaluate(){
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
    updateStrength(p);
  }

  function updateStrength(p){
    var $fill = $('.scf-strength-fill');
    var $text = $('.scf-strength-text');
    if(!$fill.length) return;
    if(!p){
      $fill.css({width:'0',background:'#d9534f'});
      if($text.length) $text.text('強度: -');
      return;
    }
    var score = 0;
    if(typeof zxcvbn === 'function'){
      try { score = zxcvbn(p).score; } catch(e){ score = 0; }
    } else {
      // 簡易フォールバック: 条件数ベース
      var s=0; if(/[A-Z]/.test(p)) s++; if(/[a-z]/.test(p)) s++; if(/\d/.test(p)) s++; if(/[^A-Za-z0-9]/.test(p)) s++; if(p.length>=12) s++; score = Math.min(4, Math.floor(s/5*4));
    }
    var widths = ['20%','40%','60%','80%','100%'];
    var colors = ['#d9534f','#f0ad4e','#f7e463','#5bc0de','#5cb85c'];
    var labels = ['とても弱い','弱い','やや弱い','普通','強い'];
    $fill.css({width: widths[score] || '20%', background: colors[score] || '#d9534f'});
    if($text.length) $text.text('強度: ' + (labels[score] || '-'));
  }

  $(document).on('input','#scf_password,#scf_password_confirm', evaluate);
  $(function(){ evaluate(); });
})(jQuery);

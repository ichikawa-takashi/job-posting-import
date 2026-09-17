/* ============================================================
   パスワード入力欄に「表示・非表示」の目のボタンを付ける
   ------------------------------------------------------------
   ・ページ内の input[type=password] を自動で見つけて付けます
   ・data-pw-toggle を付けた input[type=text] にも付きます
     （パスワード変更ダイアログのように、最初から見えている欄）
   ・あとから追加された欄（ダイアログの中など）にも自動で付きます
   ・HTMLの書き換えは不要です
   ============================================================ */
(function () {
  'use strict';

  var CLOSED = 'M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z'
             + 'M12 9.2a2.8 2.8 0 100 5.6 2.8 2.8 0 000-5.6z';
  var OPEN_EXTRA = 'M3.5 3.5l17 17';

  function icon(shown) {
    // shown = true なら「隠す」意味の斜線つきアイコンにする
    return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
      + '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z" '
      + 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"></path>'
      + '<circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="1.7"></circle>'
      + (shown
          ? '<path d="' + OPEN_EXTRA + '" fill="none" stroke="currentColor" '
            + 'stroke-width="1.7" stroke-linecap="round"></path>'
          : '')
      + '</svg>';
  }

  function attach(input) {
    if (!input || input.dataset.pwReady === '1') return;
    input.dataset.pwReady = '1';

    // 入力欄を包んで、右端にボタンを置けるようにする
    var wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';                 // フォームを送信させない
    btn.className = 'pw-eye';
    btn.tabIndex = -1;                   // Tab移動の邪魔をしない
    btn.setAttribute('aria-label', 'パスワードを表示');
    btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = icon(false);
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var shown = input.type === 'text';
      // カーソル位置を保ったまま切り替える
      var pos = null;
      try { pos = input.selectionStart; } catch (e) { pos = null; }

      input.type = shown ? 'password' : 'text';
      btn.innerHTML = icon(!shown);
      btn.setAttribute('aria-label', shown ? 'パスワードを表示' : 'パスワードを隠す');
      btn.setAttribute('aria-pressed', shown ? 'false' : 'true');

      input.focus();
      if (pos !== null) { try { input.setSelectionRange(pos, pos); } catch (e) {} }
    });
  }

  function scan(root) {
    var sel = 'input[type="password"], input[data-pw-toggle]';
    (root.querySelectorAll ? root.querySelectorAll(sel) : []).forEach(attach);
    if (root.matches && root.matches(sel)) attach(root);
  }

  function init() {
    scan(document);
    // ダイアログなど、あとから出てくる欄にも付ける
    if (window.MutationObserver) {
      new MutationObserver(function (list) {
        list.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType === 1) scan(n);
          });
        });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  // 読み込みのタイミングに関係なく動くよう、いま実行しつつ
  // 読み込み完了時にもう一度走らせる（二重に付かないよう attach 側で防いでいる）
  init();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
})();

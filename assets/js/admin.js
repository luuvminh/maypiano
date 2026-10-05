/* Settings > May Piano: reads the bank's QR picture in the browser and fills in the account fields. */
(function () {
  'use strict';
  var input = document.getElementById('mp-qr-file'), out = document.getElementById('mp-qr-result');
  if (!input || !out) return;
  function say(text, ok) { out.textContent = text; out.style.color = ok ? '#007017' : '#b32d2e'; }
  function set(id, value) { var el = document.getElementById(id); if (el && value) el.value = value; }
  function read(img) {
    var max = 1600, tries = [1, 0.6, 0.35];
    for (var t = 0; t < tries.length; t++) {
      var scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight)) * tries[t];
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.naturalWidth * scale));
      c.height = Math.max(1, Math.round(img.naturalHeight * scale));
      var ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0, c.width, c.height);
      var data = ctx.getImageData(0, 0, c.width, c.height);
      var found = window.jsQR(data.data, c.width, c.height);
      if (found && found.data) return found.data;
    }
    return '';
  }
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    var img = new Image();
    img.onload = function () {
      var text = read(img);
      URL.revokeObjectURL(img.src);
      if (!text) { say('Không đọc được mã QR trong ảnh này. Bạn thử ảnh rõ hơn, hoặc điền tay ba ô bên dưới.', false); return; }
      var info = window.MayPianoVietQR.parse(text);
      if (!info) { say('Ảnh có mã QR, nhưng không phải mã chuyển khoản VietQR. Bạn điền tay ba ô bên dưới.', false); return; }
      set('maypiano_bank_bin', info.bin);
      set('maypiano_account', info.account);
      if (info.name) set('maypiano_account_name', info.name);
      say('Đã đọc: mã ngân hàng ' + info.bin + ', số tài khoản ' + info.account + (info.valid ? '' : ' (mã kiểm tra không khớp, bạn so lại số tài khoản)') + '. Bấm Lưu thay đổi ở cuối trang.', info.valid);
    };
    img.onerror = function () { say('Không mở được ảnh này.', false); };
    img.src = URL.createObjectURL(file);
  });
})();

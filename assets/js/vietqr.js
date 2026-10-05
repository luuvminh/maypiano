/* VietQR (NAPAS 247) transfer codes: builds the text a banking app reads from a QR, and reads one back. */
(function () {
  'use strict';
  function tlv(id, value) { var v = String(value); return id + (v.length < 10 ? '0' : '') + v.length + v; }
  function crc16(text) {
    var crc = 0xFFFF;
    for (var i = 0; i < text.length; i++) {
      crc ^= text.charCodeAt(i) << 8;
      for (var b = 0; b < 8; b++) crc = crc & 0x8000 ? ((crc << 1) ^ 0x1021) & 0xFFFF : (crc << 1) & 0xFFFF;
    }
    return ('0000' + crc.toString(16).toUpperCase()).slice(-4);
  }
  /* Bank apps accept plain letters, digits and spaces in the transfer note. */
  function plain(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').replace(/[^A-Za-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
  }
  /* o: { bin, account, amount (whole VND, optional), memo (optional) } */
  function build(o) {
    var bin = String(o.bin || '').replace(/\D/g, ''), account = String(o.account || '').replace(/\s/g, '');
    if (bin.length !== 6 || !account) return '';
    var amount = Math.round(Number(o.amount) || 0), memo = plain(o.memo).slice(0, 50);
    var text = tlv('00', '01') + tlv('01', amount > 0 ? '12' : '11')
      + tlv('38', tlv('00', 'A000000727') + tlv('01', tlv('00', bin) + tlv('01', account)) + tlv('02', 'QRIBFTTA'))
      + tlv('53', '704') + (amount > 0 ? tlv('54', amount) : '') + tlv('58', 'VN')
      + (memo ? tlv('62', tlv('08', memo)) : '') + '6304';
    return text + crc16(text);
  }
  function fields(text) {
    var out = {}, i = 0;
    while (i + 4 <= text.length) {
      var id = text.substr(i, 2), len = parseInt(text.substr(i + 2, 2), 10);
      if (isNaN(len) || i + 4 + len > text.length) return null;
      out[id] = text.substr(i + 4, len);
      i += 4 + len;
    }
    return i === text.length ? out : null;
  }
  /* Returns { bin, account, amount, memo, name, valid } or null when the text is not a VietQR transfer code. */
  function parse(text) {
    text = String(text || '').trim();
    var top = fields(text);
    if (!top || !top['38']) return null;
    var acct = fields(top['38']);
    if (!acct || acct['00'] !== 'A000000727' || !acct['01']) return null;
    var bene = fields(acct['01']);
    if (!bene || !bene['00'] || !bene['01']) return null;
    var extra = top['62'] ? fields(top['62']) : null;
    return {
      bin: bene['00'], account: bene['01'], service: acct['02'] || '',
      amount: top['54'] ? Number(top['54']) : 0, memo: extra && extra['08'] ? extra['08'] : '', name: top['59'] || '',
      valid: text.length > 8 && crc16(text.slice(0, -4)) === text.slice(-4).toUpperCase()
    };
  }
  /* The code drawn as an SVG picture, ready for an <img src>. Needs the QR generator script. */
  function image(text) {
    if (!text || typeof window.qrcode !== 'function') return '';
    var qr = window.qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    var n = qr.getModuleCount(), quiet = 4, size = n + quiet * 2, path = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (qr.isDark(r, c)) path += 'M' + (c + quiet) + ' ' + (r + quiet) + 'h1v1h-1z';
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' + path + '"/></svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }
  window.MayPianoVietQR = { build: build, parse: parse, image: image, plain: plain, crc16: crc16 };
})();

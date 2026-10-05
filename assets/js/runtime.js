/* May Piano page runtime: renders the <x-dc> template with its Component class and keeps it in sync with state. */
(function () {
  'use strict';
  var pending = new Set();
  function flush() { pending.forEach(function (m) { m.render(); }); pending.clear(); }
  function schedule(m) { if (!pending.size) requestAnimationFrame(flush); pending.add(m); }

  function DCLogic(props) { this.props = props || {}; this.state = {}; this.__mount = null; }
  DCLogic.prototype.setState = function (u) {
    var n = typeof u === 'function' ? u(this.state) : u;
    this.state = Object.assign({}, this.state, n);
    if (this.__mount) schedule(this.__mount);
  };
  DCLogic.prototype.forceUpdate = function () { if (this.__mount) schedule(this.__mount); };
  window.DCLogic = DCLogic;

  function look(path, scopes) {
    path = path.trim();
    if (path === 'true') return true;
    if (path === 'false') return false;
    var keys = path.split('.');
    for (var i = scopes.length - 1; i >= 0; i--) {
      if (keys[0] in scopes[i]) {
        var v = scopes[i];
        for (var k = 0; k < keys.length; k++) v = v == null ? undefined : v[keys[k]];
        return v;
      }
    }
    return undefined;
  }
  var WHOLE = /^\s*\{\{([^}]+)\}\}\s*$/;
  var HOLE = /\{\{([^}]+)\}\}/g;
  function interp(str, scopes) {
    var m = str.match(WHOLE);
    if (m) return look(m[1], scopes);
    return str.replace(HOLE, function (_, p) { var v = look(p, scopes); return v == null ? '' : String(v); });
  }
  var EVENTS = { onclick: 'click', onchange: 'input', oninput: 'input', onsubmit: 'submit' };

  function build(node, scopes, out) {
    if (node.nodeType === 3) {
      var t = node.nodeValue;
      out.appendChild(document.createTextNode(t.indexOf('{{') >= 0 ? String(interp(t, scopes) == null ? '' : interp(t, scopes)) : t));
      return;
    }
    if (node.nodeType !== 1) return;
    var tag = node.tagName.toLowerCase();
    var i, kids = node.childNodes;
    if (tag === 'sc-for') {
      var list = interp(node.getAttribute('list') || '', scopes) || [];
      var as = node.getAttribute('as') || 'item';
      list.forEach(function (item, idx) {
        var s = {}; s[as] = item; s.$index = idx;
        var sc = scopes.concat([s]);
        for (var j = 0; j < kids.length; j++) build(kids[j], sc, out);
      });
      return;
    }
    if (tag === 'sc-if') {
      if (interp(node.getAttribute('value') || '', scopes)) for (i = 0; i < kids.length; i++) build(kids[i], scopes, out);
      return;
    }
    var el = node.cloneNode(false);
    var attrs = node.attributes;
    for (i = 0; i < attrs.length; i++) {
      var a = attrs[i];
      var lname = a.name.toLowerCase();
      if (a.value.indexOf('{{') < 0) continue;
      var v = interp(a.value, scopes);
      if (EVENTS[lname] || lname === 'ref') {
        el.removeAttribute(a.name);
        if (typeof v === 'function') {
          if (lname === 'ref') el.__ref = v;
          else { el.__h = el.__h || {}; el.__h[EVENTS[lname]] = v; }
        }
      } else if (v == null || typeof v === 'function') el.removeAttribute(a.name);
      else el.setAttribute(a.name, String(v));
    }
    for (i = 0; i < kids.length; i++) build(kids[i], scopes, el);
    out.appendChild(el);
  }

  function syncAttrs(o, n) {
    var i, a;
    for (i = o.attributes.length - 1; i >= 0; i--) { a = o.attributes[i]; if (!n.hasAttribute(a.name)) o.removeAttribute(a.name); }
    for (i = 0; i < n.attributes.length; i++) { a = n.attributes[i]; if (o.getAttribute(a.name) !== a.value) o.setAttribute(a.name, a.value); }
    if (o.tagName === 'INPUT' && n.hasAttribute('value') && o.value !== n.getAttribute('value')) o.value = n.getAttribute('value');
  }
  function patch(oldP, newP, refs) {
    var o = oldP.firstChild, n = newP.firstChild;
    while (o || n) {
      var oNext = o ? o.nextSibling : null, nNext = n ? n.nextSibling : null;
      if (!n) oldP.removeChild(o);
      else if (!o) { oldP.appendChild(n); collect(n, refs); }
      else if (o.nodeType !== n.nodeType || o.nodeName !== n.nodeName) { oldP.replaceChild(n, o); collect(n, refs); }
      else if (o.nodeType === 3) { if (o.nodeValue !== n.nodeValue) o.nodeValue = n.nodeValue; }
      else {
        syncAttrs(o, n);
        o.__h = n.__h; o.__ref = n.__ref;
        if (o.__ref) refs.push(o);
        patch(o, n, refs);
      }
      o = oNext; n = nNext;
    }
  }
  function collect(node, refs) {
    if (node.nodeType !== 1) return;
    if (node.__ref) refs.push(node);
    for (var c = node.firstChild; c; c = c.nextSibling) collect(c, refs);
  }

  function mount(root) {
    var script = document.querySelector('script[data-dc-script]');
    if (!script) return;
    var helmet = root.querySelector('helmet');
    if (helmet) { while (helmet.firstChild) document.head.appendChild(helmet.firstChild); helmet.remove(); }
    var template = root.cloneNode(true);
    var Component = new Function('DCLogic', script.textContent + '\nreturn Component;')(DCLogic);
    var inst = new Component({});
    var m = {
      render: function () {
        var vals = inst.renderVals();
        var frag = document.createElement('div');
        for (var c = template.firstChild; c; c = c.nextSibling) build(c, [vals], frag);
        var refs = [];
        patch(root, frag, refs);
        refs.forEach(function (el) { try { el.__ref(el); } catch (e) {} });
      }
    };
    Object.keys({ click: 1, input: 1, submit: 1 }).forEach(function (type) {
      root.addEventListener(type, function (e) {
        for (var el = e.target; el && el !== root; el = el.parentNode) {
          if (el.__h && el.__h[type]) { el.__h[type](e); return; }
        }
      });
    });
    root.textContent = '';
    m.render();
    root.setAttribute('data-ready', '1');
    inst.__mount = m;
    if (inst.componentDidMount) inst.componentDidMount();
  }

  function start() { var roots = document.querySelectorAll('x-dc'); for (var i = 0; i < roots.length; i++) mount(roots[i]); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  /*
   * Talks to the site. Resolves with the answer, rejects with an Error carrying .code and .status.
   * Pages are cached, so nothing secret is baked into them: a signed-in visitor (the owner testing)
   * gets a fresh pass from the site first, everyone else needs none.
   */
  var cfg = window.MayPianoCfg;
  var pass = null;
  function getPass() {
    if (!pass) {
      pass = fetch(cfg.ajax + '?action=rest-nonce', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (t) { t = String(t).trim(); return /^[a-f0-9]{6,20}$/.test(t) ? t : ''; })
        .catch(function () { return ''; });
    }
    return pass;
  }
  if (cfg && cfg.rest) {
    window.MayPianoApi = function (path, data) {
      return getPass().then(function (nonce) {
        var headers = { 'Content-Type': 'application/json' };
        if (nonce) headers['X-WP-Nonce'] = nonce;
        return fetch(cfg.rest + path, { method: 'POST', headers: headers, credentials: 'same-origin', body: JSON.stringify(data || {}) });
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (body) {
          if (r.ok) return body;
          var e = new Error((body && body.code) || 'http_' + r.status);
          e.code = (body && body.code) || '';
          e.status = r.status;
          throw e;
        });
      });
    };
  }
  /* Fire-and-forget version, for the email sign-up on the home page. */
  window.MayPianoSend = function (kind, data) {
    if (window.MayPianoApi) window.MayPianoApi(kind, data).catch(function () {});
  };
})();

/*
 * Banner cookie. La config (window.SSG_CONSENT) è scritta in testa a questo file
 * dal clonatore e poi dal pannello.
 *
 * Linee guida Garante 2021: la X chiude rifiutando, "Rifiuta" ha lo stesso peso di
 * "Accetta", nessuna categoria preselezionata, scelta modificabile in ogni momento
 * (elementi [data-ssg-consent-open]), nuova richiesta solo a scadenza o se cambia la versione.
 */
(function () {
  var C = window.SSG_CONSENT || {};
  var CATS = C.categories || [];
  var SERVICES = C.services || [];
  var NAME = 'ssg_consent';
  var T = Object.assign({
    title: 'Questo sito usa i cookie',
    body: 'Usiamo cookie tecnici necessari al funzionamento del sito e, solo con il tuo consenso, cookie di terze parti: {services}. Puoi accettarli, rifiutarli o scegliere quali consentire. Chiudendo il banner rifiuti i cookie non necessari.',
    accept: 'Accetta tutti',
    reject: 'Rifiuta',
    customize: 'Personalizza',
    save: 'Salva le preferenze',
    necessary: 'Necessari',
    necessaryDesc: 'Indispensabili per il funzionamento del sito. Sempre attivi.',
    policy: 'Cookie policy',
    privacy: 'Privacy policy',
    close: 'Chiudi e rifiuta',
    onlyTechnical: 'Questo sito usa solo cookie tecnici, necessari al suo funzionamento, che non richiedono il tuo consenso.',
    placeholder: 'Questo contenuto è fornito da {service}, che usa cookie di {category}.',
    placeholderBtn: 'Accetta e mostra'
  }, C.texts || {});

  var state = read();
  var root = null;

  function read() {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
      var s = m && JSON.parse(decodeURIComponent(m[1]));
      return s && s.v === C.version ? s : null;
    } catch (e) {
      return null;
    }
  }

  function has(cat) {
    return !!(state && state.cats && state.cats[cat]);
  }

  function save(cats, method) {
    var revoked = state && Object.keys(state.cats || {}).some(function (k) { return state.cats[k] && !cats[k]; });
    state = { v: C.version, ts: Date.now(), id: (state && state.id) || rid(), cats: cats };
    document.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(state)) + '; Max-Age=' + (C.expiry_days || 180) * 86400 +
      '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    log(method);
    close();
    // uno script già eseguito non si può "spegnere": se il consenso viene revocato si ricarica
    if (revoked) location.reload(); else apply();
  }

  function rid() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function log(method) {
    if (!C.log_endpoint) return;
    var payload = JSON.stringify({ site: C.site, id: state.id, v: state.v, cats: state.cats, method: method, page: location.href });
    try {
      if (!navigator.sendBeacon || !navigator.sendBeacon(C.log_endpoint, payload)) {
        fetch(C.log_endpoint, { method: 'POST', body: payload, keepalive: true });
      }
    } catch (e) { /* il log non deve mai bloccare il sito */ }
  }

  function all(value) {
    var cats = {};
    CATS.forEach(function (c) { cats[c.id] = value; });
    return cats;
  }

  // ------------------------------------------------------------- attivazione

  function apply() {
    document.querySelectorAll('[data-ssg-consent]').forEach(function (el) {
      if (has(el.getAttribute('data-ssg-consent'))) activate(el);
    });
  }

  function activate(el) {
    if (el.tagName === 'SCRIPT') {
      var s = document.createElement('script');
      for (var i = 0; i < el.attributes.length; i++) {
        var a = el.attributes[i];
        if (a.name !== 'type' && a.name.indexOf('data-ssg-') !== 0) s.setAttribute(a.name, a.value);
      }
      if (el.getAttribute('data-ssg-type')) s.type = el.getAttribute('data-ssg-type');
      if (!el.src) s.text = el.text;
      el.parentNode.replaceChild(s, el);
      return;
    }
    var tpl = el.querySelector('template');
    if (!tpl) return;
    var frag = tpl.content.cloneNode(true);
    var nodes = Array.prototype.slice.call(frag.children);
    el.parentNode.replaceChild(frag, el);
    // widget Elementor congelati: vanno inizializzati ora che sono nel DOM
    var ef = window.elementorFrontend;
    if (ef && ef.elementsHandler && window.jQuery) {
      nodes.forEach(function (n) {
        if (n.classList && n.classList.contains('elementor-element')) ef.elementsHandler.runReadyTrigger(window.jQuery(n));
      });
    }
  }

  function placeholders() {
    document.querySelectorAll('.ssg-ph[data-ssg-consent]').forEach(function (ph) {
      if (ph.querySelector('.ssg-ph-box')) return;
      var svc = byId(ph.getAttribute('data-ssg-service'));
      var cat = catById(ph.getAttribute('data-ssg-consent'));
      var box = el('div', 'ssg-ph-box');
      box.appendChild(el('p', '', T.placeholder.replace('{service}', svc ? svc.name : '').replace('{category}', cat ? cat.label.toLowerCase() : '')));
      var b = el('button', 'ssg-btn ssg-btn-primary', T.placeholderBtn);
      b.type = 'button';
      b.onclick = function () {
        var cats = Object.assign(all(false), state ? state.cats : {});
        cats[ph.getAttribute('data-ssg-consent')] = true;
        save(cats, 'placeholder');
      };
      box.appendChild(b);
      ph.appendChild(box);
    });
  }

  // ------------------------------------------------------------- interfaccia

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  function byId(id) {
    return SERVICES.filter(function (s) { return s.id === id; })[0];
  }

  function catById(id) {
    return CATS.filter(function (c) { return c.id === id; })[0];
  }

  function button(text, cls, fn) {
    var b = el('button', 'ssg-btn ' + cls, text);
    b.type = 'button';
    b.onclick = fn;
    return b;
  }

  function open(withPrefs) {
    close();
    root = el('div', 'ssg-cc');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', T.title);

    var x = button('×', 'ssg-x', function () { save(all(false), 'close'); });
    x.setAttribute('aria-label', T.close);
    root.appendChild(x);
    root.appendChild(el('p', 'ssg-title', T.title));
    if (!SERVICES.length) {
      x.onclick = close;
      root.appendChild(el('p', 'ssg-body', T.onlyTechnical));
      root.appendChild(policyLinks());
      document.body.appendChild(root);
      return;
    }
    var names = SERVICES.map(function (s) { return s.name; }).join(', ');
    root.appendChild(el('p', 'ssg-body', T.body.replace('{services}', names)));

    var prefs = el('div', 'ssg-prefs');
    prefs.hidden = !withPrefs;
    prefs.appendChild(row('necessary', T.necessary, T.necessaryDesc, true, true));
    CATS.forEach(function (c) {
      var list = SERVICES.filter(function (s) { return s.category === c.id; }).map(function (s) { return s.name; }).join(', ');
      prefs.appendChild(row(c.id, c.label, c.description + (list ? ' (' + list + ')' : ''), has(c.id), false));
    });
    root.appendChild(prefs);

    var actions = el('div', 'ssg-actions');
    actions.appendChild(button(T.reject, 'ssg-btn-secondary', function () { save(all(false), 'reject'); }));
    var custom = button(withPrefs ? T.save : T.customize, 'ssg-btn-secondary', function () {
      if (prefs.hidden) {
        prefs.hidden = false;
        custom.textContent = T.save;
        return;
      }
      var cats = {};
      CATS.forEach(function (c) { cats[c.id] = root.querySelector('#ssg-cat-' + c.id).checked; });
      save(cats, 'custom');
    });
    actions.appendChild(custom);
    actions.appendChild(button(T.accept, 'ssg-btn-primary', function () { save(all(true), 'accept'); }));
    root.appendChild(actions);
    root.appendChild(policyLinks());
    document.body.appendChild(root);
  }

  function policyLinks() {
    var links = el('p', 'ssg-links');
    if (C.policy_url) links.appendChild(link(T.policy, C.policy_url));
    if (C.privacy_url) links.appendChild(link(T.privacy, C.privacy_url));
    return links;
  }

  function row(id, label, desc, checked, disabled) {
    var r = el('label', 'ssg-row');
    var cb = el('input');
    cb.type = 'checkbox';
    cb.id = 'ssg-cat-' + id;
    cb.checked = checked;
    cb.disabled = disabled;
    var txt = el('span');
    txt.appendChild(el('strong', '', label));
    txt.appendChild(el('small', '', desc));
    r.appendChild(cb);
    r.appendChild(txt);
    return r;
  }

  function link(text, href) {
    var a = el('a', '', text);
    a.href = href;
    return a;
  }

  function close() {
    if (root) root.remove();
    root = null;
  }

  function css() {
    var accent = C.accent || '#1a1a1a';
    var s = el('style');
    s.textContent =
      '.ssg-cc{position:fixed;z-index:2147483000;right:16px;bottom:16px;width:420px;max-width:calc(100vw - 32px);max-height:calc(100vh - 32px);overflow:auto;box-sizing:border-box;padding:22px 22px 14px;background:#fff;color:#222;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.18);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}' +
      '.ssg-cc *{box-sizing:border-box}' +
      '.ssg-cc .ssg-title{margin:0 24px 8px 0;font-weight:700;font-size:16px}' +
      '.ssg-cc .ssg-body{margin:0 0 14px}' +
      '.ssg-cc .ssg-x{position:absolute;top:8px;right:8px;width:32px;height:32px;padding:0;border:0;background:none;color:#222;font-size:24px;line-height:1;cursor:pointer}' +
      '.ssg-cc .ssg-prefs{margin:0 0 14px;border-top:1px solid #eee}' +
      '.ssg-cc .ssg-row{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #eee;cursor:pointer}' +
      '.ssg-cc .ssg-row input{margin-top:3px;accent-color:' + accent + '}' +
      '.ssg-cc .ssg-row small{display:block;color:#666;font-size:12px}' +
      '.ssg-cc .ssg-actions{display:flex;gap:8px;flex-wrap:wrap}' +
      '.ssg-btn{flex:1 1 0;min-width:110px;padding:10px 12px;border-radius:6px;border:1px solid ' + accent + ';font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;cursor:pointer}' +
      '.ssg-btn-primary{background:' + accent + ';color:#fff}' +
      '.ssg-btn-secondary{background:#fff;color:#222}' +
      '.ssg-cc .ssg-links{margin:12px 0 0;font-size:12px;text-align:center}' +
      '.ssg-cc .ssg-links a{color:#222;margin:0 8px}' +
      '.ssg-ph{position:relative;display:flex;align-items:center;justify-content:center;min-height:200px;background:#f1f1f1;color:#222}' +
      '.ssg-ph-box{max-width:360px;padding:16px;text-align:center;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}' +
      '.ssg-ph-box .ssg-btn{flex:none}' +
      '@media (max-width:480px){.ssg-cc{right:0;bottom:0;width:100%;max-width:100%;border-radius:12px 12px 0 0}}';
    document.head.appendChild(s);
  }

  function init() {
    css();
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-ssg-consent-open]');
      if (!t) return;
      e.preventDefault();
      open(true);
    });
    // nessun servizio di terze parti = nessun banner (bastano i cookie tecnici)
    if (!SERVICES.length) return;
    placeholders();
    apply();
    if (!state) open(false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

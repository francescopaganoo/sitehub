/*
 * Modulo cookie: banner di consenso e blocco preventivo degli script che tracciano.
 *  - gli script da bloccare si scrivono così (partono solo dopo il consenso):
 *      <script type="text/plain" data-ssg-consent="statistics" src="https://…"></script>
 *    e le iframe (mappe, video) con data-ssg-src al posto di src
 *  - categorie: statistics, marketing; il banner compare solo se il sito ne usa almeno una
 *    (impostate nel pannello o trovate nella pagina)
 *  - Rifiuta ha lo stesso peso di Accetta; la X chiude rifiutando (linee guida del Garante)
 *  - consenso salvato nel browser per 6 mesi; il pannello può chiederlo di nuovo a tutti (version)
 *  - riapertura: qualunque elemento con data-ssg-cookie-settings o link a #cookie-settings
 *  - Google Consent Mode v2 se il sito usa gtag; evento "ssg:consent" sul document
 */
(function () {
  if (!SSG.modules.cookies) return;

  var CFG = SSG.cookies || {};
  var KEY = 'ssg_consent';
  var MAX_AGE = 180 * 24 * 3600 * 1000;
  var CATEGORIES = {
    statistics: { label: 'Statistiche', text: 'Ci aiutano a capire come viene usato il sito, in forma aggregata.' },
    marketing: { label: 'Marketing', text: 'Servono a mostrare pubblicità e contenuti di terze parti in linea con i tuoi interessi.' }
  };
  var TEXT = CFG.text || 'Usiamo cookie tecnici, necessari al funzionamento del sito, e, solo con il tuo consenso, cookie di altro tipo per le finalità indicate. Puoi accettarli, rifiutarli o scegliere quali consentire.';
  var COLOR = CFG.color || '#1f2937';

  var consent = read();
  var root = null;

  function read() {
    try {
      var c = JSON.parse(localStorage.getItem(KEY));
      if (c && c.v === CFG.version && Date.now() - c.at < MAX_AGE) return c;
    } catch (e) { /* storage non disponibile: si chiede a ogni pagina */ }
    return null;
  }

  function allowed(category) {
    return category === 'necessary' || !!(consent && consent.c[category]);
  }

  // categorie usate dal sito: quelle impostate nel pannello più quelle trovate nella pagina
  function used() {
    var list = (CFG.categories || []).slice();
    document.querySelectorAll('[data-ssg-consent]').forEach(function (el) {
      var c = el.getAttribute('data-ssg-consent');
      if (CATEGORIES[c] && list.indexOf(c) < 0) list.push(c);
    });
    return Object.keys(CATEGORIES).filter(function (c) { return list.indexOf(c) >= 0; });
  }

  // ------------------------------------------------------------------ blocco preventivo

  function activate() {
    document.querySelectorAll('script[type="text/plain"][data-ssg-consent]').forEach(function (old) {
      if (!allowed(old.getAttribute('data-ssg-consent'))) return;
      var s = document.createElement('script');
      Array.prototype.forEach.call(old.attributes, function (a) {
        if (a.name !== 'type' && a.name !== 'data-ssg-consent') s.setAttribute(a.name, a.value);
      });
      s.type = old.getAttribute('data-ssg-type') || 'text/javascript';
      if (!old.src) s.text = old.text;
      old.parentNode.replaceChild(s, old);
    });
    document.querySelectorAll('[data-ssg-src][data-ssg-consent]').forEach(function (el) {
      var category = el.getAttribute('data-ssg-consent');
      var note = el.previousElementSibling && el.previousElementSibling.__ssgNote ? el.previousElementSibling : null;
      if (allowed(category)) {
        if (note) note.remove();
        el.style.display = '';
        el.setAttribute('src', el.getAttribute('data-ssg-src'));
        el.removeAttribute('data-ssg-src');
      } else if (!note) {
        placeholder(el, category);
      }
    });
  }

  // al posto di una mappa o di un video bloccati: avviso con il pulsante per consentirli
  function placeholder(el, category) {
    var box = document.createElement('div');
    box.__ssgNote = true;
    var rect = el.getBoundingClientRect();
    box.style.cssText = 'display:block;width:100%;height:' + Math.max(rect.height || +el.getAttribute('height') || 0, 160) + 'px';
    // shadow DOM: lo stile del sito non tocca l'avviso
    var shadow = box.attachShadow ? box.attachShadow({ mode: 'open' }) : box;
    var style = document.createElement('style');
    style.textContent = ':host{all:initial;display:block}' +
      '.p{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;height:100%;box-sizing:border-box;' +
      'padding:20px;text-align:center;background:#f3f4f6;color:#374151;font:14px/1.4 var(--ssg-font,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}' +
      'p{margin:0;max-width:420px}button{padding:8px 16px;border:0;border-radius:6px;cursor:pointer;font:inherit;font-weight:600;color:#fff;background:' + COLOR + '}' +
      'button:focus-visible{outline:3px solid #2563eb;outline-offset:2px}';
    var wrap = document.createElement('div');
    wrap.className = 'p';
    var p = document.createElement('p');
    p.textContent = 'Questo contenuto usa cookie di ' + CATEGORIES[category].label.toLowerCase() + ' e viene mostrato solo con il tuo consenso.';
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Consenti e mostra';
    b.addEventListener('click', function () {
      var c = consent ? JSON.parse(JSON.stringify(consent.c)) : {};
      c[category] = true;
      save(c);
    });
    wrap.appendChild(p);
    wrap.appendChild(b);
    shadow.appendChild(style);
    shadow.appendChild(wrap);
    el.style.display = 'none';
    el.parentNode.insertBefore(box, el);
  }

  // dopo una revoca gli script già partiti non si fermano: si tolgono i loro cookie e si ricarica
  function clearTrackers() {
    var host = location.hostname.split('.');
    document.cookie.split(';').forEach(function (c) {
      var name = c.split('=')[0].trim();
      if (!/^(_ga|_gid|_gat|_gcl|_fbp|_fbc|_hj|_clck|_clsk|_uet)/.test(name)) return;
      for (var i = 0; i < host.length - 1; i++) {
        var domain = host.slice(i).join('.');
        document.cookie = name + '=; Max-Age=0; path=/; domain=.' + domain;
      }
      document.cookie = name + '=; Max-Age=0; path=/';
    });
  }

  function save(c) {
    var before = consent;
    consent = { v: CFG.version, at: Date.now(), c: {} };
    Object.keys(CATEGORIES).forEach(function (k) { consent.c[k] = !!c[k]; });
    try { localStorage.setItem(KEY, JSON.stringify(consent)); } catch (e) { /* vale per questa pagina */ }
    close();
    signal();
    var revoked = before && Object.keys(CATEGORIES).some(function (k) { return before.c[k] && !consent.c[k]; });
    if (revoked) {
      clearTrackers();
      location.reload();
      return;
    }
    activate();
  }

  function signal() {
    var c = consent ? consent.c : {};
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', {
        analytics_storage: c.statistics ? 'granted' : 'denied',
        ad_storage: c.marketing ? 'granted' : 'denied',
        ad_user_data: c.marketing ? 'granted' : 'denied',
        ad_personalization: c.marketing ? 'granted' : 'denied'
      });
    }
    if (window.dataLayer && window.dataLayer.push) window.dataLayer.push({ event: 'ssg_consent', ssg_consent: c });
    document.dispatchEvent(new CustomEvent('ssg:consent', { detail: c }));
  }

  // ------------------------------------------------------------------ banner

  var CSS =
    ':host{all:initial}' +
    '.b{position:fixed;z-index:2147483646;left:16px;right:16px;bottom:16px;max-width:760px;margin:0 auto;box-sizing:border-box;' +
    'background:#fff;color:#1f2937;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.25);padding:20px 22px;' +
    'font:15px/1.5 var(--ssg-font,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);max-height:calc(100vh - 32px);overflow:auto}' +
    '.h{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 8px}' +
    'h2{margin:0;font-size:17px;font-weight:700;color:#111827}' +
    'p{margin:0 0 14px}a{color:inherit;text-decoration:underline}' +
    '.x{border:0;background:none;font-size:22px;line-height:1;color:#6b7280;cursor:pointer;padding:4px}' +
    '.a{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}' +
    '.a button{flex:1 1 150px;padding:10px 16px;border-radius:6px;font:inherit;font-weight:600;cursor:pointer;border:2px solid ' + COLOR + '}' +
    '.o{background:#fff;color:' + COLOR + '}.f{background:' + COLOR + ';color:#fff}' +
    'button:focus-visible,input:focus-visible+span{outline:3px solid #2563eb;outline-offset:2px}' +
    '.c{border-top:1px solid #e5e7eb;padding:12px 0}.c:last-of-type{margin-bottom:12px}' +
    '.c label{display:flex;justify-content:space-between;align-items:center;gap:12px;font-weight:600;cursor:pointer}' +
    '.c small{display:block;font-weight:400;color:#6b7280;font-size:13px;margin-top:2px}' +
    '.t{position:relative;flex:none;width:44px;height:24px}.t input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer}' +
    '.t span{position:absolute;inset:0;border-radius:12px;background:#d1d5db;transition:background .15s}' +
    '.t span:after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .15s}' +
    '.t input:checked+span{background:' + COLOR + '}.t input:checked+span:after{transform:translateX(20px)}' +
    '.t input:disabled+span{opacity:.5}' +
    '@media (max-width:480px){.b{left:8px;right:8px;bottom:8px;padding:16px}}';

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text) n.textContent = text;
    return n;
  }

  function button(cls, text, onClick) {
    var b = el('button', { type: 'button', class: cls }, text);
    b.addEventListener('click', onClick);
    return b;
  }

  function toggle(name, checked, disabled) {
    var t = el('span', { class: 't' });
    var input = el('input', { type: 'checkbox', name: name });
    input.checked = checked;
    input.disabled = !!disabled;
    t.appendChild(input);
    t.appendChild(el('span'));
    return t;
  }

  function open(details) {
    close();
    var categories = used();
    var host = el('div', { id: 'ssg-cookie-banner' });
    var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style = el('style');
    style.textContent = CSS;
    shadow.appendChild(style);

    var box = el('div', { class: 'b', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'ssg-t', 'aria-describedby': 'ssg-d' });
    var head = el('div', { class: 'h' });
    head.appendChild(el('h2', { id: 'ssg-t' }, details ? 'Preferenze cookie' : 'Questo sito usa i cookie'));
    if (categories.length) {
      var x = head.appendChild(button('x', '×', function () { save({}); }));
      x.setAttribute('aria-label', 'Chiudi e rifiuta i cookie non necessari');
    }
    box.appendChild(head);

    var p = el('p', { id: 'ssg-d' }, categories.length ? TEXT + ' ' : 'Questo sito usa solo cookie tecnici, necessari al suo funzionamento, che non richiedono consenso. ');
    if (CFG.policy_url) p.appendChild(el('a', { href: CFG.policy_url }, 'Cookie policy'));
    box.appendChild(p);

    var actions = el('div', { class: 'a' });
    if (!categories.length) {
      actions.appendChild(button('f', 'Chiudi', close));
    } else if (details) {
      var list = el('div');
      var necessary = el('div', { class: 'c' });
      var label = el('label', {}, '');
      label.appendChild(el('span', {}, 'Necessari'));
      label.firstChild.appendChild(el('small', {}, 'Indispensabili al funzionamento del sito: sempre attivi.'));
      label.appendChild(toggle('necessary', true, true));
      necessary.appendChild(label);
      list.appendChild(necessary);
      categories.forEach(function (c) {
        var row = el('div', { class: 'c' });
        var l = el('label');
        l.appendChild(el('span', {}, CATEGORIES[c].label));
        l.firstChild.appendChild(el('small', {}, CATEGORIES[c].text));
        l.appendChild(toggle(c, allowed(c)));
        row.appendChild(l);
        list.appendChild(row);
      });
      box.appendChild(list);
      actions.appendChild(button('o', 'Rifiuta tutti', function () { save({}); }));
      actions.appendChild(button('o', 'Salva preferenze', function () {
        var c = {};
        list.querySelectorAll('input:not(:disabled)').forEach(function (i) { c[i.name] = i.checked; });
        save(c);
      }));
      actions.appendChild(button('f', 'Accetta tutti', function () { save({ statistics: true, marketing: true }); }));
    } else {
      actions.appendChild(button('o', 'Rifiuta', function () { save({}); }));
      actions.appendChild(button('o', 'Personalizza', function () { open(true); }));
      actions.appendChild(button('f', 'Accetta tutti', function () { save({ statistics: true, marketing: true }); }));
    }
    box.appendChild(actions);
    shadow.appendChild(box);
    document.body.appendChild(host);
    root = host;
    var first = shadow.querySelector(details ? 'input:not(:disabled), .a button' : '.a button');
    if (first && (details || consent)) first.focus();
  }

  function close() {
    if (root) root.remove();
    root = null;
  }

  // ------------------------------------------------------------------ avvio

  window.SSGConsent = {
    get: function () { return consent ? consent.c : null; },
    allowed: allowed,
    open: function () { open(true); }
  };

  function init() {
    activate();
    if (consent) signal();
    else if (used().length) open(false);

    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-ssg-cookie-settings], a[href="#cookie-settings"]');
      if (!t) return;
      e.preventDefault();
      open(true);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

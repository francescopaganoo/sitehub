/*
 * Modulo form. Funziona con qualunque HTML: basta <form data-ssg-form="nome">.
 *  - etichette ricavate da <label>, aria-label, placeholder o nome del campo
 *  - honeypot proprio + riconoscimento dei campi nascosti del tema (honeypot dei plugin)
 *  - blocca gli handler AJAX di WordPress/Elementor/CF7 (listener in capture sul document)
 *  - messaggi: [data-ssg-success] / [data-ssg-error] se presenti nel form, altrimenti messaggio automatico
 *  - data-ssg-redirect="/grazie/" per andare a una pagina di ringraziamento
 *  - eventi "ssg:success" / "ssg:error" sul form (analytics, comportamenti personalizzati)
 */
(function () {
  if (!SSG.modules.forms) return;

  var loadedAt = Date.now();
  var TEXT = {
    ok: 'Messaggio inviato correttamente. Grazie, ti risponderemo al più presto.',
    ko: 'Si è verificato un errore durante l\'invio. Riprova più tardi.',
    sending: 'Invio in corso…'
  };
  var SELECTOR = 'form[data-ssg-form]';

  function prepare(form) {
    if (form.__ssg) return;
    form.__ssg = true;
    // senza JavaScript il form invia comunque al pannello (se l'HTML non ha già un action)
    if (!form.getAttribute('action') || form.getAttribute('action') === '#') form.setAttribute('action', SSG.submitUrl);
    form.setAttribute('method', 'post');
    var hp = document.createElement('input');
    hp.type = 'text';
    hp.name = '_hp';
    hp.tabIndex = -1;
    hp.autocomplete = 'off';
    hp.setAttribute('aria-hidden', 'true');
    hp.style.cssText = 'position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0';
    form.appendChild(hp);
  }

  function labelOf(form, el) {
    var label = (el.id && form.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]')) || el.closest('label');
    var text = '';
    if (label) {
      var copy = label.cloneNode(true);
      copy.querySelectorAll('input, select, textarea').forEach(function (n) { n.remove(); });
      text = copy.textContent;
    }
    text = (text || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '').replace(/\s+/g, ' ').trim();
    return text.replace(/[\s*:]+$/, '');
  }

  function hidden(el) {
    return el.type !== 'hidden' && el.offsetParent === null && getComputedStyle(el).position !== 'fixed';
  }

  function collect(form) {
    var fields = [];
    var byName = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      var type = (el.type || el.tagName).toLowerCase();
      if (!el.name || el.disabled || el.name === '_hp' || /^(submit|button|reset|file|image|password)$/.test(type)) return;
      if ((type === 'checkbox' || type === 'radio') && !el.checked) return;
      var value = el.tagName === 'SELECT' && el.multiple
        ? Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; }).join(', ')
        : (type === 'checkbox' && el.value === 'on' ? 'Sì' : el.value);
      if (byName[el.name]) {
        byName[el.name].value += ', ' + value; // gruppi di checkbox con lo stesso nome
        return;
      }
      // gruppi (radio, checkbox con lo stesso nome): l'etichetta è del gruppo, non della prima opzione
      var group = (type === 'radio' || type === 'checkbox') && form.querySelectorAll('[name="' + el.name.replace(/"/g, '\\"') + '"]').length > 1;
      var legend = group && el.closest('fieldset') && el.closest('fieldset').querySelector('legend');
      var label = group ? (legend ? legend.textContent.replace(/\s+/g, ' ').trim() : '') : labelOf(form, el);
      var f = { name: el.name, label: label, type: type, value: value, honeypot: hidden(el) };
      byName[el.name] = f;
      fields.push(f);
    });
    return fields;
  }

  function show(form, ok, text) {
    var custom = form.querySelector(ok ? '[data-ssg-success]' : '[data-ssg-error]');
    form.querySelectorAll('[data-ssg-success], [data-ssg-error]').forEach(function (n) { n.hidden = true; });
    form.querySelectorAll('.ssg-msg').forEach(function (n) { n.remove(); });
    if (custom) {
      custom.hidden = false;
      return;
    }
    var box = document.createElement('div');
    // classi Elementor incluse: nei siti clonati il messaggio prende lo stile del tema
    box.className = 'ssg-msg ' + (ok ? 'ssg-msg-ok elementor-message elementor-message-success' : 'ssg-msg-ko elementor-message elementor-message-danger');
    box.setAttribute('role', ok ? 'status' : 'alert');
    box.textContent = text || (ok ? TEXT.ok : TEXT.ko);
    form.appendChild(box);
  }

  function send(form) {
    if (form.__ssgBusy) return;
    form.__ssgBusy = true;
    var buttons = form.querySelectorAll('[type="submit"], button:not([type])');
    buttons.forEach(function (b) { b.disabled = true; });
    form.setAttribute('aria-busy', 'true');

    var payload = {
      form: form.getAttribute('data-ssg-form') || form.getAttribute('name') || form.id || 'contatti',
      page: location.href,
      t: Date.now() - loadedAt,
      hp: form.querySelector('input[name="_hp"]') ? form.querySelector('input[name="_hp"]').value : '',
      fields: collect(form)
    };

    // text/plain = richiesta "semplice": niente preflight CORS
    fetch(SSG.submitUrl, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(payload) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, body: j }; });
      })
      .catch(function () { return { ok: false, body: {} }; })
      .then(function (res) {
        form.__ssgBusy = false;
        buttons.forEach(function (b) { b.disabled = false; });
        form.removeAttribute('aria-busy');
        var detail = { form: payload.form, response: res.body };
        if (res.ok) {
          form.dispatchEvent(new CustomEvent('ssg:success', { detail: detail, bubbles: true }));
          var redirect = form.getAttribute('data-ssg-redirect');
          if (redirect) return void (location.href = redirect);
          form.reset();
          show(form, true, res.body.message);
        } else {
          form.dispatchEvent(new CustomEvent('ssg:error', { detail: detail, bubbles: true }));
          show(form, false, res.body.error && res.body.error.code !== 'internal_error' ? res.body.error.message : '');
        }
      });
  }

  function init() {
    var css = document.createElement('style');
    css.textContent = '.ssg-msg{margin-top:12px;padding:10px 14px;border-radius:6px;font-size:15px;line-height:1.4}' +
      '.ssg-msg-ok{background:#e7f6ec;color:#14532d}.ssg-msg-ko{background:#fdecec;color:#7f1d1d}';
    document.head.appendChild(css);

    document.querySelectorAll(SELECTOR).forEach(prepare);
    // form aggiunti dopo (popup, sezioni caricate via JS)
    new MutationObserver(function () { document.querySelectorAll(SELECTOR).forEach(prepare); })
      .observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.matches || !form.matches(SELECTOR)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      prepare(form);
      send(form);
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

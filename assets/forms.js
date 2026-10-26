/*
 * Invio form verso il pannello. Il listener è in fase di capture sul document,
 * quindi scatta prima degli handler AJAX di Elementor/CF7 e li blocca.
 */
(function () {
  var MSG_OK = 'Messaggio inviato correttamente. Grazie, ti risponderemo al più presto.';
  var MSG_KO = 'Si è verificato un errore durante l\'invio. Riprova più tardi.';

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-ssg-form]')) return;
    e.preventDefault();
    e.stopImmediatePropagation();

    var btn = form.querySelector('[type="submit"]');
    var data = new FormData(form);
    data.append('_form', form.getAttribute('data-ssg-form'));
    data.append('_page', location.href);

    form.querySelectorAll('.ssg-message').forEach(function (n) { n.remove(); });
    if (btn) btn.disabled = true;

    fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          return { ok: r.ok && j.ok !== false, message: j.message };
        });
      })
      .catch(function () { return { ok: false }; })
      .then(function (res) {
        show(form, res.ok, res.message);
        if (res.ok) form.reset();
        if (btn) btn.disabled = false;
      });
  }, true);

  function show(form, ok, message) {
    var d = document.createElement('div');
    d.className = 'ssg-message elementor-message ' + (ok ? 'elementor-message-success' : 'elementor-message-danger');
    d.setAttribute('role', ok ? 'status' : 'alert');
    d.textContent = message || (ok ? MSG_OK : MSG_KO);
    form.appendChild(d);
  }
})();

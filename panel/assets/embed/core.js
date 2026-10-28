/*
 * Base dello script, sempre presente: elementi della pagina legati a un modulo.
 * <li data-ssg-modulo="blog" hidden><a href="/blog/">Blog</a></li> si vede solo se il modulo blog è attivo:
 * nell'HTML parte nascosto (hidden), così non compare per un attimo quando il modulo è spento.
 */
(function () {
  var active = SSG.modules || {};
  var css = document.createElement('style');
  // il CSS del sito non deve poter rimostrare un elemento nascosto (es. .menu li { display: flex })
  css.textContent = '[data-ssg-modulo][hidden]{display:none!important}';
  document.head.appendChild(css);

  function apply() {
    document.querySelectorAll('[data-ssg-modulo]').forEach(function (el) {
      var on = el.getAttribute('data-ssg-modulo').split(/\s+/).some(function (m) { return active[m]; });
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', '');
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
})();

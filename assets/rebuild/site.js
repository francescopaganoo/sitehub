/* Comportamenti del sito: menu mobile e lightbox della galleria. Nessuna dipendenza. */
(function () {
  // menu: il pulsante apre/chiude la lista; un clic su un link (anche àncora) la richiude
  document.querySelectorAll('.menu').forEach(function (nav) {
    var btn = nav.querySelector('.menu-toggle');
    var list = nav.querySelector('.menu-list');
    if (!btn || !list) return;
    btn.addEventListener('click', function () {
      var open = list.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    list.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        list.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  });

  // lightbox: link a immagini con data-lightbox, navigazione con frecce e tastiera
  var links = Array.prototype.slice.call(document.querySelectorAll('a[data-lightbox]'));
  if (!links.length) return;
  var box, img, index = 0;

  function show(i) {
    index = (i + links.length) % links.length;
    img.src = links[index].href;
    img.alt = (links[index].querySelector('img') || {}).alt || '';
  }
  function close() {
    box.remove();
    box = null;
    document.removeEventListener('keydown', keys);
  }
  function keys(e) {
    if (e.key === 'Escape') close();
    if (e.key === 'ArrowRight') show(index + 1);
    if (e.key === 'ArrowLeft') show(index - 1);
  }
  function button(cls, label, text, fn) {
    var b = document.createElement('button');
    b.className = cls;
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.textContent = text;
    b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
    return b;
  }

  links.forEach(function (a, i) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      box = document.createElement('div');
      box.className = 'lightbox';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      img = document.createElement('img');
      box.appendChild(img);
      box.appendChild(button('lb-close', 'Chiudi', '×', close));
      if (links.length > 1) {
        box.appendChild(button('lb-prev', 'Precedente', '‹', function () { show(index - 1); }));
        box.appendChild(button('lb-next', 'Successiva', '›', function () { show(index + 1); }));
      }
      box.addEventListener('click', close);
      document.body.appendChild(box);
      document.addEventListener('keydown', keys);
      show(i);
    });
  });
})();

/* Menu mobile e ingrandimento delle foto della galleria (se la pagina non ha la galleria, quella parte non fa nulla) */
(function () {
  // menu: il pulsante apre e chiude; un clic su una voce lo richiude
  var toggle = document.querySelector('.menu-toggle');
  var menu = document.getElementById('menu');
  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Chiudi il menu' : 'Apri il menu');
    });
    menu.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        menu.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // galleria: clic su una miniatura → foto grande; frecce, Esc e clic fuori per chiudere
  var links = Array.prototype.slice.call(document.querySelectorAll('.gallery a'));
  if (!links.length) return;
  var box = null;
  var img = null;
  var index = 0;

  function show(i) {
    index = (i + links.length) % links.length;
    img.src = links[index].href;
    img.alt = links[index].querySelector('img').alt;
  }

  function close() {
    box.remove();
    box = null;
    document.removeEventListener('keydown', onKey);
    links[index].focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') close();
    if (e.key === 'ArrowRight') show(index + 1);
    if (e.key === 'ArrowLeft') show(index - 1);
  }

  function button(cls, label, text, action) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.setAttribute('aria-label', label);
    b.textContent = text;
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      action();
    });
    return b;
  }

  links.forEach(function (link, i) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      box = document.createElement('div');
      box.className = 'lightbox';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', 'Foto ingrandita');
      img = document.createElement('img');
      box.append(img, button('close', 'Chiudi', '×', close),
        button('prev', 'Foto precedente', '‹', function () { show(index - 1); }),
        button('next', 'Foto successiva', '›', function () { show(index + 1); }));
      box.addEventListener('click', close);
      document.body.appendChild(box);
      document.addEventListener('keydown', onKey);
      show(i);
      box.querySelector('.close').focus();
    });
  });
})();

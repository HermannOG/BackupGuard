/**
 * Ayuda en pantalla: botones "?" y "💡".
 *  - Clic en el botón: abre o cierra su explicación.
 *  - Solo una abierta a la vez.
 *  - Clic afuera, tecla Esc o desplazar la página: se cierra.
 *  - La caja se posiciona sobre la pantalla (position: fixed), así no la
 *    recortan las tablas con scroll ni los paneles.
 */
(function () {
  function cerrarTodas(excepto) {
    document.querySelectorAll('.ayuda-pop.abierta').forEach(function (pop) {
      if (pop !== excepto) {
        pop.classList.remove('abierta');
        var b = pop.querySelector('button');
        if (b) b.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function posicionar(pop) {
    var boton = pop.querySelector('.ayuda-btn');
    var caja = pop.querySelector('.ayuda-caja');
    var r = boton.getBoundingClientRect();
    var ancho = caja.offsetWidth;
    var alto = caja.offsetHeight;
    var izq = Math.min(Math.max(12, r.left - 10), window.innerWidth - ancho - 12);
    var arriba = r.bottom + 8;
    if (arriba + alto > window.innerHeight - 12 && r.top - alto - 8 > 12) {
      arriba = r.top - alto - 8;   // no cabe abajo: se abre hacia arriba
    }
    caja.style.left = izq + 'px';
    caja.style.top = arriba + 'px';
  }

  document.addEventListener('click', function (e) {
    var boton = e.target.closest('.ayuda-btn');
    if (boton) {
      e.preventDefault();
      e.stopPropagation();
      var pop = boton.closest('.ayuda-pop');
      var abrir = !pop.classList.contains('abierta');
      cerrarTodas(pop);
      pop.classList.toggle('abierta', abrir);
      boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
      if (abrir) posicionar(pop);
      return;
    }
    if (!e.target.closest('.ayuda-caja')) cerrarTodas(null);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrarTodas(null);
  });
  window.addEventListener('scroll', function () { cerrarTodas(null); }, { passive: true });
  window.addEventListener('resize', function () { cerrarTodas(null); });
})();
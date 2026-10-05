<?php
/**
 * Componentes de ayuda en pantalla, compartidos por todas las vistas.
 *
 * La herramienta debe entenderse sola, incluso para alguien que nunca la vio:
 *
 *  - ayuda()  → botón "?" que abre una explicación corta de un campo o concepto.
 *  - porque() → botón "💡" con la justificación: por qué existe esa opción y
 *               qué riesgo reduce (control preventivo, enunciado EIF402).
 *  - guia()   → panel plegable "¿Qué hago aquí?" al inicio de cada pantalla.
 *
 * El comportamiento (abrir, cerrar al tocar afuera, una sola abierta a la vez)
 * vive en assets/js/ayuda.js. El contenido se escribe en el código de cada
 * vista, nunca viene del usuario, por eso se acepta HTML.
 */

/** Botón "?" con una explicación corta. */
function ayuda(string $titulo, string $contenidoHtml): string
{
    return burbujaAyuda('?', 'ayuda-btn', $titulo, $contenidoHtml, 'Ayuda: ' . $titulo);
}

/** Botón "💡" con la justificación de una decisión. */
function porque(string $titulo, string $contenidoHtml): string
{
    return burbujaAyuda('💡', 'ayuda-btn ayuda-just', $titulo, $contenidoHtml, 'Por qué: ' . $titulo);
}

function burbujaAyuda(string $icono, string $clase, string $titulo, string $html, string $etiqueta): string
{
    return '<span class="ayuda-pop">'
         .   '<button type="button" class="' . $clase . '" aria-expanded="false" aria-label="' . e($etiqueta) . '">' . $icono . '</button>'
         .   '<span class="ayuda-caja" role="tooltip">'
         .     '<strong>' . e($titulo) . '</strong>'
         .     '<span class="ayuda-texto">' . $html . '</span>'
         .   '</span>'
         . '</span>';
}

/**
 * Guía plegable "¿Qué hago aquí?".
 * @param array $pasos  lista de ['titulo' => ..., 'texto' => ...] (texto acepta HTML)
 * @param string|null $porqueHtml  párrafo final "Por qué importa" (opcional)
 * @param bool $abierta  abierta por defecto (útil cuando la pantalla está vacía)
 */
function guia(string $resumen, array $pasos, ?string $porqueHtml = null, bool $abierta = false): string
{
    $html  = '<details class="guia"' . ($abierta ? ' open' : '') . '>';
    $html .= '<summary><span class="guia-icono" aria-hidden="true">?</span>'
          .  '<span class="guia-resumen"><strong>¿Qué hago aquí?</strong> ' . e($resumen) . '</span>'
          .  '<span class="guia-flecha" aria-hidden="true"></span></summary>';
    $html .= '<div class="guia-cuerpo"><ol class="guia-pasos">';
    foreach ($pasos as $i => $p) {
        $html .= '<li><span class="guia-num">' . ($i + 1) . '</span><div>'
              .  '<strong>' . e($p['titulo']) . '</strong>'
              .  '<p>' . $p['texto'] . '</p></div></li>';
    }
    $html .= '</ol>';
    if ($porqueHtml) {
        $html .= '<div class="guia-porque">'
              .  '<span class="guia-porque-icono" aria-hidden="true">💡</span>'
              .  '<div><strong>Por qué importa</strong><p>' . $porqueHtml . '</p></div>'
              .  '</div>';
    }
    $html .= '</div></details>';
    return $html;
}
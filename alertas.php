<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/Alertas.php';

requiereLogin();

$motor = new Alertas();
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    requiereEdicion();
    $motor->marcarAtendida((int) ($_POST['id'] ?? 0), $_SESSION['usuario']['nombre_usuario']);
    bitacora('atender_alerta', 'alertas', (int) ($_POST['id'] ?? 0));
    $mensaje = 'Alerta marcada como atendida. No volverá a aparecer mientras la condición siga igual; si cambia, se levanta una alerta nueva.';
}

$alertas = $motor->evaluar();
$conteo  = $motor->conteo();

$tituloPagina = 'Alertas';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Alertas preventivas</h1>
    <p class="sub">Condiciones que pueden comprometer la disponibilidad o la integridad de la información,
       detectadas antes de que se conviertan en un problema.</p>
  </div>
</div>

<?= guia(
    'BackupGuard revisa continuamente las bases y estrategias, y avisa aquí cuando algo puede fallar.',
    [
        ['titulo' => 'Empezá por las críticas',
         'texto'  => '<b>Crítica</b>: hay un riesgo real ahora (un respaldo falló o no corrió). <b>Advertencia</b>: algo '
                   . 'puede fallar pronto. <b>Recomendación</b>: una mejora. <b>Información</b>: solo para que lo sepas.'],
        ['titulo' => 'Leé qué hacer',
         'texto'  => 'Cada alerta explica qué riesgo representa y la acción sugerida, con un botón directo a la estrategia o la base.'],
        ['titulo' => 'Marcala como atendida',
         'texto'  => 'Cuando la resolviste o la revisaste, marcala. Si la condición vuelve a cambiar, aparece una alerta nueva.'],
    ],
    'Un control preventivo actúa <b>antes</b> de que ocurra la pérdida. Detectar una estrategia sin aprobar, un respaldo '
  . 'que no corrió o un disco casi lleno permite corregirlo mientras todavía no hace falta recuperar nada.',
    false
) ?>

<?= $mensaje ? aviso('exito', $mensaje) : '' ?>

<div class="rejilla c4">
  <div class="metrica bad"><div class="valor"><?= $conteo['critica'] ?></div><div class="etiqueta">Críticas</div></div>
  <div class="metrica warn"><div class="valor"><?= $conteo['advertencia'] ?></div><div class="etiqueta">Advertencias</div></div>
  <div class="metrica ok"><div class="valor"><?= $conteo['recomendacion'] ?></div><div class="etiqueta">Recomendaciones</div></div>
  <div class="metrica info"><div class="valor"><?= $conteo['informacion'] ?></div><div class="etiqueta">Informativas</div></div>
</div>

<div class="panel">
  <div class="panel-titulo">
    <h2>Alertas vigentes <?php if ($alertas): ?><span class="contador"><?= count($alertas) ?></span><?php endif; ?></h2>
    <span class="nota">Ordenadas de mayor a menor severidad</span>
  </div>

  <?php if (!$alertas): ?>
    <div class="vacio-guia">
      <div class="vacio-icono vacio-ok" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
      </div>
      <h3>Todo en orden</h3>
      <p>No hay condiciones de riesgo detectadas en este momento.</p>
    </div>
  <?php else: ?>
    <div class="lista-alertas">
      <?php foreach ($alertas as $a): ?>
        <?= tarjetaAlerta($a, false, puedeEditar()) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Evidencia de una ejecución: todo lo que el enunciado pide registrar,
 * incluida la salida cruda de RMAN y el script exacto que se ejecutó.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$ej = $repo->obtenerEjecucion((int) ($_GET['id'] ?? 0));
if (!$ej) { header('Location: historial.php'); exit; }

$tituloPagina = 'Evidencia de ejecución';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Evidencia de ejecución #<?= (int) $ej['id'] ?></h1>
    <p class="sub">
      <?= insigniaResultado($ej['resultado']) ?>
      <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?>
      · <a href="estrategia-detalle.php?id=<?= (int) $ej['estrategia_id'] ?>"><?= e($ej['estrategia_nombre']) ?></a>
    </p>
  </div>
  <div class="acciones"><a class="boton" href="historial.php">Volver al historial</a></div>
</div>

<?php if ((int) $ej['simulado'] === 1): ?>
  <?= aviso('informacion', 'Esta ejecución se realizó en modo simulación: no se invocó RMAN ni se ' .
            'modificó ninguna base de datos. La salida es representativa, no real.') ?>
<?php endif; ?>

<div class="rejilla c2">
  <div class="panel">
    <h2>Datos de la ejecución</h2>
    <div class="tabla-envoltura">
      <table>
        <tbody>
          <tr><th>Estrategia</th><td><?= e($ej['estrategia_nombre']) ?></td></tr>
          <tr><th>Base de datos</th><td><?= e($ej['base_nombre']) ?></td></tr>
          <tr><th>Tipo de respaldo</th><td><?= e(str_replace('_', ' ', $ej['tipo_respaldo'])) ?></td></tr>
          <tr><th>Origen</th><td><?= e($ej['origen']) ?></td></tr>
          <tr><th>Hora de inicio</th><td class="mono"><?= formatoFecha($ej['inicio']) ?></td></tr>
          <tr><th>Hora de finalización</th><td class="mono"><?= formatoFecha($ej['fin']) ?></td></tr>
          <tr><th>Duración</th><td><?= formatoDuracion($ej['duracion_seg'] !== null ? (int) $ej['duracion_seg'] : null) ?></td></tr>
          <tr><th>Resultado</th><td><?= insigniaResultado($ej['resultado']) ?></td></tr>
          <tr><th>Código de salida</th><td class="mono"><?= $ej['codigo_salida'] ?? '—' ?></td></tr>
          <tr><th>Ubicación</th><td class="mono"><?= e($ej['ubicacion'] ?: '—') ?></td></tr>
          <tr><th>Archivos generados</th><td><?= $ej['archivos_generados'] ?? '—' ?></td></tr>
          <tr><th>Tamaño</th><td><?= formatoBytes($ej['tamano_bytes'] !== null ? (int) $ej['tamano_bytes'] : null) ?></td></tr>
          <tr><th>Ejecutado por</th><td><?= e($ej['ejecutado_por'] ?: '—') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h2>Errores y advertencias</h2>
    <?php if ($ej['mensaje_error']): ?>
      <div class="aviso <?= $ej['resultado'] === 'fallido' ? 'error' : 'advertencia' ?>">
        <pre class="script" style="background:transparent;border:none;padding:0"><?= e($ej['mensaje_error']) ?></pre>
      </div>
    <?php else: ?>
      <?= aviso('exito', 'RMAN no reportó errores ni advertencias en esta ejecución.') ?>
    <?php endif; ?>
    <p class="nota">Un código de salida 0 no basta: BackupGuard revisa el log completo buscando
       códigos RMAN- y ORA- antes de dar una ejecución por exitosa.</p>
  </div>
</div>

<div class="panel">
  <h2>Salida de RMAN</h2>
  <?php if ($ej['salida_rman']): ?>
    <pre class="script"><?= e($ej['salida_rman']) ?></pre>
  <?php else: ?>
    <div class="vacio"><p>No se capturó salida de RMAN para esta ejecución.</p></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Script ejecutado</h2>
  <p class="nota">Es el script tal como estaba en el momento de la ejecución, aunque la estrategia
     se haya modificado después.</p>
  <pre class="script"><?= e($ej['script_ejecutado']) ?></pre>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Evidencia de una ejecución: todo lo que el enunciado pide registrar,
 * incluida la salida cruda de RMAN y el script exacto que se ejecutó.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/RmanBuilder.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$ej = $repo->obtenerEjecucion((int) ($_GET['id'] ?? 0));
if (!$ej) { header('Location: historial.php'); exit; }
$est = $repo->obtener((int) $ej['estrategia_id']);

$duracion = $ej['duracion_seg'] !== null ? (int) $ej['duracion_seg'] : null;
$ventana  = (int) ($est['ventana_minutos'] ?? 0);
$codigoEst = RmanBuilder::codigo((int) $ej['estrategia_id']);

// Comprobaciones que respaldan el resultado: lo que BackupGuard revisó.
// estado: ok | mal | na (no aplica o no se pudo comprobar)
$checks = [];
if ($ej['resultado'] !== 'en_curso') {
    $checks[] = ['RMAN terminó con código de salida 0',
        $ej['codigo_salida'] === null ? 'na' : ((int) $ej['codigo_salida'] === 0 ? 'ok' : 'mal'),
        'Código devuelto: ' . ($ej['codigo_salida'] ?? '—')];
    $checks[] = ['El log no contiene errores RMAN- ni ORA-',
        $ej['mensaje_error'] ? 'mal' : 'ok',
        $ej['mensaje_error'] ? 'Se encontraron errores (ver abajo)' : 'Se revisó la salida completa'];
    $checks[] = ['Terminó dentro de la ventana de respaldo',
        ($ventana <= 0 || $duracion === null) ? 'na' : ($duracion <= $ventana * 60 ? 'ok' : 'mal'),
        $ventana > 0 ? 'Ventana de ' . $ventana . ' min · tardó ' . formatoDuracion($duracion) : 'La estrategia no define ventana'];
    $checks[] = ['Se encontraron archivos del respaldo en el destino',
        $ej['archivos_generados'] === null ? 'na' : ((int) $ej['archivos_generados'] > 0 ? 'ok' : 'mal'),
        $ej['archivos_generados'] === null
            ? 'No inspeccionable desde aquí (Fast Recovery Area o cinta)'
            : (int) $ej['archivos_generados'] . ' archivo(s) · ' . formatoBytes($ej['tamano_bytes'] !== null ? (int) $ej['tamano_bytes'] : null)];
}
$iconos = ['ok' => '✓', 'mal' => '✕', 'na' => '–'];

$tituloPagina = 'Evidencia de ejecución';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <nav class="miga" aria-label="Ruta">
      <a href="historial.php">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Historial
      </a>
      <span class="miga-sep" aria-hidden="true">/</span>
      <span class="miga-actual">Ejecución #<?= (int) $ej['id'] ?></span>
    </nav>
    <h1>Evidencia de ejecución <span class="muted">#<?= (int) $ej['id'] ?></span></h1>
    <p class="sub insignias-fila">
      <?= insigniaResultado($ej['resultado']) ?>
      <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?>
      <?= insigniaOrigen($ej['origen']) ?>
      <a href="estrategia-detalle.php?id=<?= (int) $ej['estrategia_id'] ?>" class="enlace-est">
        <span class="codigo-mini"><?= e($codigoEst) ?></span><?= e($ej['estrategia_nombre']) ?>
      </a>
    </p>
  </div>
</div>

<?php
  // Una ejecución "en curso" que ya superó el tiempo máximo de RMAN no está
  // corriendo: el proceso se interrumpió antes de guardar el resultado.
  $limite = (int) (config()['timeout_rman'] ?? 3600);
  $segundos = time() - strtotime((string) $ej['inicio']);
?>
<?php if ($ej['resultado'] === 'en_curso' && $segundos > $limite): ?>
  <div class="aviso error">
    <span class="titulo">La ejecución parece interrumpida</span>
    <p>Empezó hace más de <?= (int) round($limite / 60) ?> minutos y nunca se cerró. Lo más probable es que PHP haya
       cortado la página antes de que RMAN terminara, por eso no hay salida ni resultado. Revisá el log de RMAN en la
       carpeta de destino: puede que el respaldo sí se haya completado.</p>
  </div>
<?php elseif ($ej['resultado'] === 'en_curso'): ?>
  <div class="aviso informacion">
    <span class="titulo">Ejecución en curso</span>
    <p>RMAN todavía está trabajando. La salida, el resultado y las comprobaciones aparecen cuando termina.
       Recargá la página en unos minutos.</p>
  </div>
<?php endif; ?>

<?php if ((int) $ej['simulado'] === 1): ?>
  <?= aviso('informacion', 'Esta ejecución se realizó en modo simulación: no se invocó RMAN ni se modificó ' .
            'ninguna base de datos. La salida es representativa, no real.') ?>
<?php endif; ?>

<!-- Datos clave de un vistazo -->
<div class="rejilla c4 datos-clave">
  <div class="metrica resultado-<?= e($ej['resultado']) ?>">
    <div class="etiqueta">Resultado</div>
    <div class="valor-texto"><?= e(['exitoso' => 'Exitoso', 'advertencia' => 'Con advertencias',
                                    'fallido' => 'Fallido', 'en_curso' => 'En curso'][$ej['resultado']] ?? $ej['resultado']) ?></div>
  </div>
  <div class="metrica">
    <div class="etiqueta">Duración</div>
    <div class="valor-texto"><?= formatoDuracion($duracion) ?></div>
  </div>
  <div class="metrica">
    <div class="etiqueta">Tamaño</div>
    <div class="valor-texto"><?= formatoBytes($ej['tamano_bytes'] !== null ? (int) $ej['tamano_bytes'] : null) ?></div>
  </div>
  <div class="metrica">
    <div class="etiqueta">Ubicación</div>
    <div class="valor-texto chico mono"><?= e($ej['ubicacion'] ?: '—') ?></div>
  </div>
</div>

<div class="rejilla c2">
  <div class="panel">
    <h2>Datos de la ejecución</h2>
    <dl class="datos-lista">
      <dt>Estrategia</dt>   <dd><?= e($codigoEst . ' · ' . $ej['estrategia_nombre']) ?></dd>
      <dt>Base de datos</dt><dd><?= e($ej['base_nombre']) ?></dd>
      <dt>Tipo de respaldo</dt><dd><?= e(tipoRespaldoLegible($ej['tipo_respaldo'])) ?></dd>
      <dt>Inicio</dt>       <dd class="mono"><?= formatoFecha($ej['inicio']) ?></dd>
      <dt>Fin</dt>          <dd class="mono"><?= formatoFecha($ej['fin']) ?></dd>
      <dt>Código de salida</dt><dd class="mono"><?= $ej['codigo_salida'] ?? '—' ?></dd>
      <dt>Archivos generados</dt><dd><?= $ej['archivos_generados'] ?? '—' ?></dd>
      <dt>Log en disco</dt>
      <dd class="mono ruta"><?= e($ej['archivo_log'] ?: '—') ?>
        <?php if ($ej['archivo_log'] && !is_file($ej['archivo_log'])): ?>
          <span class="insignia bad">ya no existe</span>
        <?php endif; ?></dd>
      <dt>Ejecutado por</dt><dd><?= e($ej['ejecutado_por'] ?: '—') ?></dd>
    </dl>
  </div>

  <div class="panel">
    <h2>Comprobaciones
      <?= porque('¿Por qué no basta con que RMAN termine?',
          'Un respaldo puede «terminar» y aun así no servir. Por eso BackupGuard no se queda con el código de salida: '
        . 'revisa el log completo, compara la duración con la ventana y busca los archivos en el destino. '
        . 'El enunciado pide no asumir que una estrategia es correcta solo porque el script se ejecutó.') ?></h2>

    <?php if ($checks): ?>
      <ul class="checklist">
        <?php foreach ($checks as [$texto, $estado, $detalle]): ?>
          <li class="check-<?= $estado ?>">
            <span class="check-icono"><?= $iconos[$estado] ?></span>
            <div><strong><?= e($texto) ?></strong><span><?= e($detalle) ?></span></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="nota">La ejecución todavía está en curso.</p>
    <?php endif; ?>

    <?php if ($ej['mensaje_error']): ?>
      <div class="aviso <?= $ej['resultado'] === 'fallido' ? 'error' : 'advertencia' ?>">
        <span class="titulo"><?= $ej['resultado'] === 'fallido' ? 'Errores reportados' : 'Advertencias' ?></span>
        <pre class="error-texto"><?= e($ej['mensaje_error']) ?></pre>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-titulo">
    <h2>Salida de RMAN
      <?= ayuda('Salida de RMAN', 'Todo lo que RMAN escribió durante la ejecución. Las líneas con '
              . '<span class="mono">RMAN-</span> u <span class="mono">ORA-</span> son errores de Oracle.') ?></h2>
  </div>
  <?php if ($ej['salida_rman']): ?>
    <pre class="script"><?= e($ej['salida_rman']) ?></pre>
  <?php else: ?>
    <div class="vacio"><p><?= $ej['resultado'] === 'en_curso'
        ? 'La salida de RMAN se guarda cuando la ejecución termina.'
        : 'No se capturó salida de RMAN para esta ejecución.' ?></p></div>
  <?php endif; ?>
</div>

<details class="panel desplegable" open>
  <summary>
    <h2>Script ejecutado
      <?= ayuda('Script ejecutado', 'El script tal como estaba en el momento de la ejecución, aunque la estrategia '
              . 'se haya modificado después. Así la evidencia no cambia con el tiempo.') ?></h2>
    <span class="desplegable-flecha" aria-hidden="true"></span>
  </summary>
  <pre class="script"><?= e($ej['script_ejecutado']) ?></pre>
</details>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

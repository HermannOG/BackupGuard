<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/RmanBuilder.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$estrategiaId = isset($_GET['estrategia']) && $_GET['estrategia'] !== '' ? (int) $_GET['estrategia'] : null;
$ejecuciones = $repo->historial($estrategiaId, 100);
$estrategias = $repo->listar();
$resumen = $repo->resumen(30);

$tituloPagina = 'Historial';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Historial de ejecuciones</h1>
    <p class="sub">La evidencia de cada respaldo: cuándo corrió, cuánto tardó y cómo terminó.</p>
  </div>
</div>

<?= guia(
    'Cada fila es una ejecución real (o simulada) de una estrategia. Abrila para ver la evidencia completa.',
    [
        ['titulo' => 'Revisá el resultado',
         'texto'  => '<b>Exitoso</b>: terminó sin errores. <b>Con advertencias</b>: terminó, pero algo merece atención '
                   . '(por ejemplo, se pasó de la ventana). <b>Fallido</b>: RMAN reportó un error.'],
        ['titulo' => 'Abrí la evidencia',
         'texto'  => 'Ahí está el script exacto que se ejecutó, la salida completa de RMAN, la ubicación y el tamaño del respaldo.'],
        ['titulo' => 'Filtrá por estrategia',
         'texto'  => 'Para seguir la trayectoria de una sola estrategia a lo largo del tiempo.'],
    ],
    'No alcanza con que un respaldo esté programado: hay que poder <b>demostrar</b> que ocurrió, cuándo y con qué '
  . 'resultado. Ese registro es la diferencia entre tener un mecanismo de respaldo y gestionar una estrategia.',
    !$ejecuciones
) ?>

<p class="rotulo-seccion">Últimos 30 días</p>
<div class="rejilla c4">
  <div class="metrica ok"><div class="valor"><?= $resumen['exitoso'] ?></div><div class="etiqueta">Exitosos</div></div>
  <div class="metrica warn"><div class="valor"><?= $resumen['advertencia'] ?></div><div class="etiqueta">Con advertencias</div></div>
  <div class="metrica bad"><div class="valor"><?= $resumen['fallido'] ?></div><div class="etiqueta">Fallidos</div></div>
  <div class="metrica info"><div class="valor"><?= $resumen['en_curso'] ?></div>
    <div class="etiqueta">En curso <?= ayuda('En curso', 'Ejecuciones que empezaron y todavía no terminaron. Si una '
        . 'queda «en curso» por mucho tiempo, el proceso pudo haberse interrumpido.') ?></div></div>
</div>

<div class="panel">
  <div class="panel-titulo">
    <h2>Ejecuciones <?php if ($ejecuciones): ?><span class="contador"><?= count($ejecuciones) ?></span><?php endif; ?></h2>
    <form method="get" class="filtro">
      <label for="estrategia">Estrategia</label>
      <select id="estrategia" name="estrategia" onchange="this.form.submit()">
        <option value="">Todas</option>
        <?php foreach ($estrategias as $es): ?>
          <option value="<?= (int) $es['id'] ?>" <?= $estrategiaId === (int) $es['id'] ? 'selected' : '' ?>>
            <?= e(RmanBuilder::codigo((int) $es['id']) . ' · ' . $es['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$ejecuciones): ?>
    <div class="vacio-guia">
      <div class="vacio-icono" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
      </div>
      <h3>Todavía no hay ejecuciones</h3>
      <p>Cuando una estrategia aprobada se ejecute, sola o con «Ejecutar ahora», su evidencia aparecerá aquí.</p>
      <a class="boton primario" href="estrategias.php">Ir a estrategias</a>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla-historial">
        <thead>
          <tr><th>Inicio</th><th>Estrategia</th><th>Tipo</th><th>Duración</th><th>Origen</th><th>Resultado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($ejecuciones as $ej): ?>
          <tr class="fila-<?= e($ej['resultado']) ?>">
            <td class="mono"><?= formatoFecha($ej['inicio']) ?></td>
            <td>
              <a href="estrategia-detalle.php?id=<?= (int) $ej['estrategia_id'] ?>" class="enlace-est">
                <span class="codigo-mini"><?= e(RmanBuilder::codigo((int) $ej['estrategia_id'])) ?></span>
                <?= e($ej['estrategia_nombre']) ?>
              </a>
              <span class="muted sub-celda">Base <?= e($ej['base_nombre']) ?></span>
            </td>
            <td><?= e(tipoRespaldoLegible($ej['tipo_respaldo'])) ?></td>
            <td class="mono"><?= formatoDuracion($ej['duracion_seg'] !== null ? (int) $ej['duracion_seg'] : null) ?></td>
            <td><?= insigniaOrigen($ej['origen']) ?></td>
            <td>
              <?= insigniaResultado($ej['resultado']) ?>
              <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?>
            </td>
            <td class="celda-acciones"><a class="boton boton-chico" href="ejecucion-detalle.php?id=<?= (int) $ej['id'] ?>">Ver evidencia</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Catálogo de estrategias — la tabla de la pizarra: código, días, horas y el
 * archivo RMAN (.rma) que corre el ejecutor. Una fila por cada par día-hora.
 *
 * Con ?ver=<id> muestra el contenido del EST###.rma leído desde el disco,
 * para comprobar que el archivo existe y es el script aprobado.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/Programacion.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$estrategias = $repo->listar();

/**
 * Filas día-hora de una estrategia, según su frecuencia.
 * @return array<int, array{0:string, 1:string}> [día, hora]
 */
function filasCatalogo(array $e): array
{
    $hora = $e['hora'] ? substr((string) $e['hora'], 0, 5) : '—';
    switch ($e['frecuencia']) {
        case 'semanal':
            $filas = array_map(
                fn($p) => [Programacion::DIAS[$p[0]] ?? '?', substr($p[1], 0, 5)],
                Programacion::horarios($e)
            );
            return $filas ?: [['Sin días', '—']];
        case 'diaria':
            return [['Todos los días', $hora]];
        case 'mensual':
            return [['Día ' . (int) $e['dia_mes'] . ' de cada mes', $hora]];
        case 'unica':
            return [[$e['fecha_inicio'] ? 'Solo el ' . $e['fecha_inicio'] : 'Una vez', $hora]];
    }
    return [['—', $hora]];
}

// Ver el .rma desde el disco.
$verId = isset($_GET['ver']) ? (int) $_GET['ver'] : null;
$verEstrategia = $verId ? $repo->obtener($verId) : null;
$contenidoRma = null;
if ($verEstrategia && $verEstrategia['archivo_rman'] && is_readable($verEstrategia['archivo_rman'])) {
    $contenidoRma = file_get_contents($verEstrategia['archivo_rman']);
    if (!mb_check_encoding($contenidoRma, 'UTF-8')) {
        $contenidoRma = mb_convert_encoding($contenidoRma, 'UTF-8', 'Windows-1252');
    }
}

$tituloPagina = 'Catálogo de estrategias';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Catálogo de estrategias</h1>
    <p class="sub">Lo que lee el ejecutor en cada vuelta: para cada estrategia, en qué día y a qué
       hora corre, y qué archivo RMAN ejecuta. Solo corren las activas con script aprobado.</p>
  </div>
</div>

<?php if ($verId): ?>
  <div class="panel">
    <h2>Archivo RMAN <?= e(RmanBuilder::codigo($verId)) ?>.rma</h2>
    <?php if ($contenidoRma !== null): ?>
      <p class="nota">Leído desde el disco: <span class="mono"><?= e($verEstrategia['archivo_rman']) ?></span>.
         Se puede correr a mano con
         <span class="mono">rman target / cmdfile='<?= e($verEstrategia['archivo_rman']) ?>'</span></p>
      <pre class="script"><?= e($contenidoRma) ?></pre>
    <?php else: ?>
      <?= aviso('advertencia', 'Esta estrategia no tiene un archivo .rma en disco. Se genera al aprobar su script.') ?>
    <?php endif; ?>
    <a class="boton" href="catalogo.php">Volver al catálogo</a>
  </div>
<?php endif; ?>

<div class="panel">
<?php if (!$estrategias): ?>
  <div class="vacio"><p>El catálogo está vacío. Creá una estrategia para agregarla.</p></div>
<?php else: ?>
  <div class="tabla-envoltura">
    <table>
      <thead>
        <tr><th>Código</th><th>Estrategia</th><th>Base</th><th>Tipo</th><th>Día</th><th>Hora</th>
            <th>Archivo RMAN</th><th>Estado</th><th>Próxima ejecución</th></tr>
      </thead>
      <tbody>
      <?php foreach ($estrategias as $es): ?>
        <?php $filas = filasCatalogo($es); $n = count($filas); ?>
        <?php foreach ($filas as $i => [$dia, $hora]): ?>
          <tr>
            <?php if ($i === 0): ?>
              <td rowspan="<?= $n ?>" class="mono"><strong><?= e(RmanBuilder::codigo((int) $es['id'])) ?></strong></td>
              <td rowspan="<?= $n ?>">
                <a href="estrategia-detalle.php?id=<?= (int) $es['id'] ?>"><?= e($es['nombre']) ?></a>
              </td>
              <td rowspan="<?= $n ?>"><?= e($es['base_nombre']) ?></td>
              <td rowspan="<?= $n ?>"><?= e(str_replace('_', ' ', $es['tipo_respaldo'])) ?></td>
            <?php endif; ?>
            <td><?= e($dia) ?></td>
            <td class="mono"><?= e($hora) ?></td>
            <?php if ($i === 0): ?>
              <td rowspan="<?= $n ?>" class="mono">
                <?php if ($es['archivo_rman']): ?>
                  <a href="catalogo.php?ver=<?= (int) $es['id'] ?>"><?= e(basename($es['archivo_rman'])) ?></a>
                <?php else: ?>
                  <span class="muted">sin aprobar</span>
                <?php endif; ?>
              </td>
              <td rowspan="<?= $n ?>">
                <?= insigniaEstado($es['estado']) ?>
                <?php if ((int) $es['aprobado'] === 1): ?><span class="insignia ok">Aprobado</span><?php endif; ?>
              </td>
              <td rowspan="<?= $n ?>" class="mono"><?= formatoFecha($es['proxima_ejecucion']) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="nota">El ejecutor (<span class="mono">iniciar-ejecutor.bat</span>) recorre este catálogo cada
     30 segundos. Cuando una estrategia llega a su día y hora, corre su archivo RMAN y deja el backup
     y el log en la carpeta destino.</p>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

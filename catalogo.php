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
require_once __DIR__ . '/includes/RmanBuilder.php';
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
    $n = Programacion::intervalo($e);
    switch ($e['frecuencia']) {
        case 'semanal':
            $filas = array_map(
                fn($p) => [(Programacion::DIAS[$p[0]] ?? '?') . ($n > 1 ? ' (cada ' . $n . ' semanas)' : ''), substr($p[1], 0, 5)],
                Programacion::horarios($e)
            );
            return $filas ?: [['Sin días', '—']];
        case 'horas':
            return [[$n === 1 ? 'Cada hora' : 'Cada ' . $n . ' horas', 'desde ' . $hora]];
        case 'diaria':
            return [[$n === 1 ? 'Todos los días' : 'Cada ' . $n . ' días', $hora]];
        case 'mensual':
            return [['Día ' . (int) $e['dia_mes'] . ($n === 1 ? ' de cada mes' : ', cada ' . $n . ' meses'), $hora]];
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
    <p class="sub">La agenda que sigue el ejecutor automático: qué estrategia corre, qué día, a qué hora y con qué archivo RMAN.</p>
  </div>
</div>

<?= guia(
    'Cada fila es una cita del ejecutor: un día y una hora en que corre el archivo RMAN de una estrategia.',
    [
        ['titulo' => 'Leé la agenda',
         'texto'  => 'Una estrategia semanal con varios días ocupa varias filas, una por cada par día-hora.'],
        ['titulo' => 'Mirá el estado',
         'texto'  => 'Solo corren solas las que están <b>Activas</b> y con el script <b>Aprobado</b>. Las demás aparecen, pero el ejecutor las salta.'],
        ['titulo' => 'Abrí el archivo RMAN',
         'texto'  => 'El enlace <span class="mono">EST###.rma</span> muestra el archivo tal como está en el disco: es exactamente lo que se ejecuta.'],
    ],
    'Separar la estrategia de su ejecución permite cambiar una decisión (por ejemplo, de diario a semanal) en un solo '
  . 'lugar, sin administrar a mano scripts y tareas programadas sueltas.',
    !$estrategias
) ?>

<?php if ($verId): ?>
  <div class="panel visor-rma">
    <div class="panel-titulo">
      <h2><span class="codigo-mini"><?= e(RmanBuilder::codigo($verId)) ?>.rma</span> Archivo RMAN en disco</h2>
      <a class="boton boton-chico" href="catalogo.php">Cerrar</a>
    </div>
    <?php if ($contenidoRma !== null): ?>
      <dl class="datos-lista">
        <dt>Ubicación</dt><dd class="mono ruta"><?= e($verEstrategia['archivo_rman']) ?></dd>
        <dt>Ejecución manual
          <?= ayuda('Ejecutar a mano', 'Si hiciera falta, el administrador puede correr este mismo archivo desde una '
                  . 'consola con este comando. Es el mismo que usa el ejecutor automático.') ?></dt>
        <dd class="mono ruta">rman target / cmdfile='<?= e($verEstrategia['archivo_rman']) ?>'</dd>
      </dl>
      <pre class="script"><?= e($contenidoRma) ?></pre>
    <?php else: ?>
      <?= aviso('advertencia', 'Esta estrategia no tiene un archivo .rma en disco. Se genera al aprobar su script.') ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-titulo">
    <h2>Agenda del ejecutor <?php if ($estrategias): ?><span class="contador"><?= count($estrategias) ?></span><?php endif; ?></h2>
  </div>

<?php if (!$estrategias): ?>
  <div class="vacio-guia">
    <div class="vacio-icono" aria-hidden="true">
      <svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
    </div>
    <h3>El catálogo está vacío</h3>
    <p>Cuando crees una estrategia, aparecerá aquí con sus días, horas y archivo RMAN.</p>
    <?php if (puedeEditar()): ?><a class="boton primario" href="estrategia-form.php">Crear estrategia</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="tabla-envoltura">
    <table class="tabla-catalogo">
      <thead>
        <tr><th>Estrategia</th><th>Tipo</th><th>Cuándo</th><th>Hora</th>
            <th>Archivo RMAN</th><th>Estado</th><th>Próxima ejecución</th></tr>
      </thead>
      <tbody>
      <?php foreach ($estrategias as $es): ?>
        <?php
          $filas = filasCatalogo($es);
          $n = count($filas);
          $corre = $es['estado'] === 'activa' && (int) $es['aprobado'] === 1;
        ?>
        <?php foreach ($filas as $i => [$dia, $hora]): ?>
          <tr class="<?= $corre ? '' : 'fila-pausada' ?> <?= $i === $n - 1 ? 'ultima-del-grupo' : '' ?>">
            <?php if ($i === 0): ?>
              <td rowspan="<?= $n ?>">
                <a href="estrategia-detalle.php?id=<?= (int) $es['id'] ?>" class="enlace-est">
                  <span class="codigo-mini"><?= e(RmanBuilder::codigo((int) $es['id'])) ?></span><?= e($es['nombre']) ?>
                </a>
                <span class="muted sub-celda">Base <?= e($es['base_nombre']) ?></span>
              </td>
              <td rowspan="<?= $n ?>"><?= e(tipoRespaldoLegible($es['tipo_respaldo'], $es['modalidad'] ?? null)) ?></td>
            <?php endif; ?>
            <td><span class="chip-cuando"><?= e($dia) ?></span></td>
            <td class="mono"><?= e($hora) ?></td>
            <?php if ($i === 0): ?>
              <td rowspan="<?= $n ?>" class="mono">
                <?php if ($es['archivo_rman']): ?>
                  <a href="catalogo.php?ver=<?= (int) $es['id'] ?>" class="enlace-archivo">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/></svg>
                    <?= e(basename($es['archivo_rman'])) ?>
                  </a>
                <?php else: ?>
                  <span class="muted">Se crea al aprobar</span>
                <?php endif; ?>
              </td>
              <td rowspan="<?= $n ?>">
                <div class="insignias-fila">
                  <?= insigniaEstado($es['estado']) ?>
                  <?= (int) $es['aprobado'] === 1 ? '<span class="insignia ok">Aprobado</span>' : '<span class="insignia warn">Sin aprobar</span>' ?>
                </div>
                <?php if (!$corre): ?><span class="muted sub-celda">El ejecutor la salta</span><?php endif; ?>
              </td>
              <td rowspan="<?= $n ?>" class="mono"><?= formatoFecha($es['proxima_ejecucion']) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="nota-ejecutor">
    <span class="nota-ejecutor-icono" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
    </span>
    <p>El ejecutor automático (<span class="mono">iniciar-ejecutor.bat</span>) recorre esta agenda cada 30 segundos.
       Cuando una estrategia llega a su día y hora, corre su archivo RMAN y deja el respaldo, el log y la evidencia.</p>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Detalle de una estrategia: el flujo completo del enunciado en una pantalla.
 *
 *   Configuración → Validación → Script RMAN → Visualización →
 *   Aprobación → Programación → Ejecución → Evidencia
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/Programacion.php';
require_once __DIR__ . '/includes/RmanBuilder.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';
require_once __DIR__ . '/includes/Ejecutor.php';

requiereLogin();

$repo = new EstrategiaRepository();
$id = (int) ($_GET['id'] ?? 0);
$e = $repo->obtener($id);
if (!$e) { header('Location: estrategias.php'); exit; }

$mensaje = $_GET['guardada'] ?? null ? 'Estrategia guardada.' : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {
            case 'generar':
                requiereEdicion();
                $repo->generarScript($id);
                bitacora('generar_script', 'estrategias', $id);
                $mensaje = 'Script RMAN generado. Revisalo antes de aprobarlo.';
                break;

            case 'aprobar':
                requiereAdmin();
                $repo->aprobar($id, $_SESSION['usuario']['nombre_usuario']);
                bitacora('aprobar_script', 'estrategias', $id);
                $mensaje = 'Script aprobado. La estrategia ya puede ejecutarse.';
                break;

            case 'activar':
            case 'desactivar':
                requiereEdicion();
                $repo->cambiarEstado($id, $accion === 'activar' ? 'activa' : 'inactiva');
                bitacora($accion . '_estrategia', 'estrategias', $id);
                $mensaje = 'Estado actualizado.';
                break;

            case 'ejecutar':
            case 'simular_fallo':
                requiereAdmin();
                $ejecutor = new Ejecutor();
                $ejecucionId = $ejecutor->ejecutar($id, 'manual', $accion === 'simular_fallo');
                header('Location: ejecucion-detalle.php?id=' . $ejecucionId);
                exit;

            case 'eliminar':
                requiereAdmin();
                $repo->eliminar($id);
                bitacora('eliminar_estrategia', 'estrategias', $id, $e['nombre']);
                header('Location: estrategias.php');
                exit;
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }

    $e = $repo->obtener($id);  // releer después de cualquier cambio
}

$bd = $repo->obtenerBase((int) $e['base_datos_id']);
$objetos = $repo->objetos($id);
$builder = new RmanBuilder($e, $bd, $objetos);
$avisos = $builder->validar();
$hayErrores = $builder->tieneErrores();
$ejecuciones = $repo->historial($id, 10);

// ---- Progreso del flujo: dónde está la estrategia y qué sigue ----
$aprobado  = (int) $e['aprobado'] === 1;
$generado  = !empty($e['script_rman']);
$activa    = $e['estado'] === 'activa';
$ejecutada = (bool) $ejecuciones;

$pasos = [
    ['Configurada',     true],
    ['Validada',        !$hayErrores],
    ['Script generado', $generado],
    ['Aprobada',        $aprobado],
    ['Activa',          $activa],
    ['Con evidencia',   $ejecutada],
];
$actual = null;
foreach ($pasos as $i => [, $hecho]) {
    if (!$hecho) { $actual = $i; break; }
}

if ($hayErrores) {
    $siguiente = ['Corregí los errores de validación',
        'La estrategia tiene configuraciones incompatibles (marcadas en rojo abajo). Editala para poder generar el script.',
        'estrategia-form.php?id=' . $id, 'Editar estrategia'];
} elseif (!$generado) {
    $siguiente = ['Generá el script RMAN',
        'BackupGuard traduce lo que configuraste en instrucciones RMAN. Nada se ejecuta todavía: primero lo vas a poder revisar.',
        '#script', 'Ir al script'];
} elseif (!$aprobado) {
    $siguiente = esAdmin()
        ? ['Revisá el script y aprobalo',
           'Leé las instrucciones que se enviarán a RMAN. Si estás de acuerdo, aprobalo: sin aprobación no se ejecuta nunca.',
           '#script', 'Revisar script']
        : ['Esperando aprobación',
           'Un administrador tiene que revisar y aprobar el script antes de que pueda ejecutarse.', null, null];
} elseif (!$activa) {
    $siguiente = ['Activá la estrategia o probala',
        'El script está aprobado. Activala para que el ejecutor automático la corra en su horario, o ejecutala ahora para probarla.',
        '#script', 'Ir a ejecutar'];
} elseif (!$ejecutada) {
    $siguiente = ['Esperando la primera ejecución',
        'Se ejecutará sola el ' . formatoFecha($e['proxima_ejecucion']) . ' si el ejecutor está corriendo. También podés ejecutarla ahora.',
        '#script', 'Ejecutar ahora'];
} else {
    $siguiente = ['Todo en orden',
        'La estrategia está aprobada, activa y ya tiene evidencia. Revisá el historial abajo para ver cada resultado.',
        '#evidencia', 'Ver evidencia'];
}

$conteo = ['error' => 0, 'advertencia' => 0, 'recomendacion' => 0, 'informacion' => 0];
foreach ($avisos as $a) { $conteo[$a['nivel']] = ($conteo[$a['nivel']] ?? 0) + 1; }
$etiquetasNivel = ['error' => 'Error', 'advertencia' => 'Advertencia',
                   'recomendacion' => 'Recomendación', 'informacion' => 'Información'];
$singular = ['error' => 'error', 'advertencia' => 'advertencia',
             'recomendacion' => 'recomendación', 'informacion' => 'nota informativa'];
$plural   = ['error' => 'errores', 'advertencia' => 'advertencias',
             'recomendacion' => 'recomendaciones', 'informacion' => 'notas informativas'];

$tituloPagina = $e['nombre'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <nav class="miga" aria-label="Ruta">
      <a href="estrategias.php">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Estrategias
      </a>
      <span class="miga-sep" aria-hidden="true">/</span>
      <span class="miga-actual"><?= e(RmanBuilder::codigo($id)) ?></span>
    </nav>
    <h1><span class="codigo-est"><?= e(RmanBuilder::codigo($id)) ?></span> <?= e($e['nombre']) ?></h1>
    <p class="sub insignias-fila">
      <?= insigniaPrioridad($e['prioridad']) ?>
      <?= insigniaEstado($e['estado']) ?>
      <?= insigniaArchivado($e['modo_archivado']) ?>
      <span class="muted">Base <?= e($e['base_nombre']) ?><?= $e['responsable'] ? ' · Responsable: ' . e($e['responsable']) : '' ?></span>
    </p>
  </div>
  <div class="acciones">
    <?php if (puedeEditar()): ?>
      <a class="boton" href="estrategia-form.php?id=<?= $id ?>">Editar</a>
      <form method="post">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="<?= $activa ? 'desactivar' : 'activar' ?>">
        <button class="boton" type="submit"><?= $activa ? 'Desactivar' : 'Activar' ?></button>
      </form>
    <?php endif; ?>
    <?php if (esAdmin()): ?>
      <form method="post" onsubmit="return confirm('¿Eliminar la estrategia y todo su historial?')">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="eliminar">
        <button class="boton peligro" type="submit">Eliminar</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?= $error   ? aviso('error', $error)   : '' ?>
<?= $mensaje ? aviso('exito', $mensaje) : '' ?>

<!-- ===================== PROGRESO ===================== -->
<div class="panel progreso-panel">
  <ol class="progreso">
    <?php foreach ($pasos as $i => [$nombrePaso, $hecho]): ?>
      <li class="<?= $hecho ? 'hecho' : ($i === $actual ? 'actual' : '') ?>">
        <span class="progreso-punto"><?= $hecho ? '✓' : $i + 1 ?></span>
        <span class="progreso-nombre"><?= e($nombrePaso) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <div class="siguiente-paso">
    <div>
      <span class="resumen-etiqueta">Siguiente paso</span>
      <strong><?= e($siguiente[0]) ?></strong>
      <p><?= e($siguiente[1]) ?></p>
    </div>
    <?php if ($siguiente[2]): ?>
      <a class="boton primario" href="<?= e($siguiente[2]) ?>"><?= e($siguiente[3]) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($e['descripcion']): ?>
  <p class="descripcion-est"><?= nl2br(e($e['descripcion'])) ?></p>
<?php endif; ?>

<!-- ===================== VALIDACIÓN ===================== -->
<div class="panel">
  <div class="panel-titulo">
    <h2>Validación
      <?= porque('¿Por qué se valida?',
          'Antes de generar el script, BackupGuard revisa que la configuración tenga sentido: por ejemplo, que no '
        . 'se pidan archived logs en una base NOARCHIVELOG. Los <b>errores</b> bloquean el script; las '
        . 'advertencias, recomendaciones e información solo avisan, porque la decisión es del administrador.') ?></h2>
    <div class="conteo-avisos">
      <?php foreach ($conteo as $nivel => $cant): if ($cant === 0) continue; ?>
        <span class="insignia <?= ['error' => 'bad', 'advertencia' => 'warn', 'recomendacion' => 'ok', 'informacion' => 'info'][$nivel] ?>">
          <?= $cant ?> <?= e($cant === 1 ? $singular[$nivel] : $plural[$nivel]) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$avisos): ?>
    <?= aviso('exito', 'La estrategia es coherente y no presenta observaciones.') ?>
  <?php else: ?>
    <?php foreach ($avisos as $a): ?>
      <div class="aviso <?= e($a['nivel']) ?>">
        <span class="titulo"><?= e($etiquetasNivel[$a['nivel']] ?? ucfirst($a['nivel'])) ?></span>
        <p><?= e($a['mensaje']) ?></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="rejilla c2 rejilla-paneles">

  <!-- ===================== TRADUCCIÓN ===================== -->
  <div class="panel">
    <h2>Qué · Cómo · Cuándo
      <?= ayuda('Traducción a RMAN', 'Cada decisión del formulario y la instrucción RMAN en que se convierte. '
              . 'Es la explicación de cómo se construye el script.') ?></h2>
    <ul class="traduccion">
      <?php foreach ($builder->explicarTraduccion() as [$clave, $texto]): ?>
        <li><span class="clave"><?= e($clave) ?></span><span><?= e($texto) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($objetos): ?>
      <p class="nota" style="margin-top:1rem">Objetos incluidos:
        <span class="mono"><?= e(implode(', ', array_column($objetos, 'nombre'))) ?></span></p>
    <?php endif; ?>

    <?php if ($e['justificacion_prioridad']): ?>
      <p class="nota" style="margin-top:1rem"><strong>Criterio de prioridad:</strong>
         <?= e($e['justificacion_prioridad']) ?></p>
    <?php endif; ?>
  </div>

  <!-- ===================== ESTADO ===================== -->
  <div class="panel">
    <h2>Estado de la automatización
      <?= ayuda('Automatización', 'El ejecutor automático (<span class="mono">iniciar-ejecutor.bat</span>) revisa cada '
              . 'pocos minutos qué estrategias activas y aprobadas tienen una ejecución pendiente y las corre.') ?></h2>
    <dl class="datos-lista">
      <dt>Script</dt>
      <dd>
        <?php if ($aprobado): ?>
          <span class="insignia ok">Aprobado</span>
          <span class="muted">por <?= e($e['aprobado_por']) ?> · <?= formatoFecha($e['aprobado_en']) ?></span>
        <?php elseif ($generado): ?>
          <span class="insignia warn">Generado, sin aprobar</span>
        <?php else: ?>
          <span class="insignia neutra">Sin generar</span>
        <?php endif; ?>
      </dd>

      <dt>Archivo RMAN</dt>
      <dd class="mono">
        <?php if ($e['archivo_rman']): ?>
          <a href="catalogo.php?ver=<?= $id ?>"><?= e($e['archivo_rman']) ?></a>
        <?php else: ?>
          <span class="muted">Se crea al aprobar el script</span>
        <?php endif; ?>
      </dd>

      <dt>Programación</dt>
      <dd><?= e(Programacion::describir($e)) ?></dd>

      <dt>Próxima ejecución</dt>
      <dd class="mono"><?= formatoFecha($e['proxima_ejecucion']) ?></dd>

      <dt>Última ejecución</dt>
      <dd class="mono"><?= formatoFecha($e['ultima_ejecucion']) ?></dd>

      <dt>Ventana</dt>
      <dd><?= $e['ventana_minutos'] ? (int) $e['ventana_minutos'] . ' minutos' : '—' ?></dd>

      <dt>Dispositivo</dt>
      <dd><?= ($e['dispositivo'] ?? 'disco') === 'cinta' ? 'Cinta (SBT)' : 'Disco' ?>
          <?= !empty($e['dispositivo_id']) ? '<span class="muted">· ' . e($e['dispositivo_id']) . '</span>' : '' ?></dd>

      <dt>Destino</dt>
      <dd class="mono"><?= ($e['dispositivo'] ?? 'disco') === 'cinta' ? 'Media manager' : e($e['destino'] ?: 'Fast Recovery Area') ?></dd>
    </dl>

    <?php if ($activa && $aprobado): ?>
      <?= aviso('exito', 'Se ejecutará sola en la próxima fecha programada, siempre que el ejecutor esté corriendo (iniciar-ejecutor.bat).') ?>
    <?php elseif ($activa): ?>
      <?= aviso('advertencia', 'Está activa, pero su script no está aprobado: no se ejecutará automáticamente.') ?>
    <?php elseif ($aprobado): ?>
      <?= aviso('informacion', 'El script está aprobado, pero la estrategia está inactiva: no se ejecutará sola hasta que la actives.') ?>
    <?php endif; ?>
  </div>

</div>

<!-- ===================== SCRIPT RMAN ===================== -->
<div class="panel" id="script">
  <div class="panel-titulo">
    <h2>Script RMAN
      <?= porque('¿Por qué hay que aprobarlo?',
          'El script es exactamente lo que se enviará a la base. Mostrarlo antes y exigir que un administrador lo '
        . 'apruebe evita que un error de configuración llegue a Oracle sin que nadie lo vea. Si la estrategia se '
        . 'edita, la aprobación se anula y hay que revisarlo de nuevo.') ?></h2>
    <?php if ($generado): ?>
      <span class="nota">Generado el <?= formatoFecha($e['script_generado_en']) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($hayErrores): ?>
    <?= aviso('error', 'No se puede generar el script mientras la estrategia tenga errores de validación.') ?>
  <?php endif; ?>

  <?php if ($generado): ?>
    <pre class="script"><?= e($e['script_rman']) ?></pre>
  <?php else: ?>
    <div class="vacio-guia">
      <div class="vacio-icono" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M8 6l-6 6 6 6M16 6l6 6-6 6"/></svg>
      </div>
      <h3>Todavía no hay script</h3>
      <p>Generalo para ver las instrucciones RMAN que corresponden a esta estrategia. Generar no ejecuta nada.</p>
    </div>
  <?php endif; ?>

  <div class="acciones-script">
    <?php if (puedeEditar()): ?>
      <form method="post">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="generar">
        <button class="boton <?= $generado ? '' : 'primario' ?>" type="submit" <?= $hayErrores ? 'disabled' : '' ?>>
          <?= $generado ? 'Regenerar script' : 'Generar script' ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if (esAdmin() && $generado && !$aprobado): ?>
      <form method="post">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="aprobar">
        <button class="boton primario" type="submit">Aprobar este script</button>
      </form>
    <?php endif; ?>

    <?php if (esAdmin() && $aprobado): ?>
      <form method="post" onsubmit="return confirm('¿Ejecutar el respaldo ahora sobre <?= e($e['base_nombre']) ?>?')">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="ejecutar">
        <button class="boton primario" type="submit">Ejecutar ahora</button>
      </form>
      <form method="post">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="simular_fallo">
        <button class="boton" type="submit">Simular un fallo</button>
      </form>
      <?= ayuda('Simular un fallo', 'Registra una ejecución <b>fallida controlada</b> sin tocar la base. Sirve para '
              . 'demostrar que la herramienta detecta el error, lo deja como evidencia y levanta la alerta, como pide '
              . 'el enunciado.') ?>
    <?php endif; ?>
  </div>
</div>

<!-- ===================== EVIDENCIA ===================== -->
<div class="panel" id="evidencia">
  <h2>Evidencia de ejecución
    <?= ayuda('Evidencia', 'Cada ejecución queda registrada con horas, duración, el script exacto, la salida de RMAN, '
            . 'los errores y dónde quedó el respaldo. Abrí una para ver el detalle completo.') ?></h2>
  <?php if (!$ejecuciones): ?>
    <div class="vacio-guia">
      <div class="vacio-icono" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M9 4h6l1 2h3v14H5V6h3z"/><path d="M9 13l2 2 4-4"/></svg>
      </div>
      <h3>Sin ejecuciones todavía</h3>
      <p>Cuando la estrategia se ejecute (sola o con «Ejecutar ahora»), cada resultado aparecerá aquí como evidencia.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table>
        <thead><tr><th>Inicio</th><th>Fin</th><th>Duración</th><th>Origen</th><th>Resultado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ejecuciones as $ej): ?>
          <tr>
            <td class="mono"><?= formatoFecha($ej['inicio']) ?></td>
            <td class="mono"><?= formatoFecha($ej['fin']) ?></td>
            <td><?= formatoDuracion($ej['duracion_seg'] !== null ? (int) $ej['duracion_seg'] : null) ?></td>
            <td><?= e($ej['origen']) ?></td>
            <td><?= insigniaResultado($ej['resultado']) ?>
                <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?></td>
            <td><a href="ejecucion-detalle.php?id=<?= (int) $ej['id'] ?>">Ver evidencia →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
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

$tituloPagina = $e['nombre'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1><?= e($e['nombre']) ?></h1>
    <p class="sub">
      <?= insigniaPrioridad($e['prioridad']) ?>
      <?= insigniaEstado($e['estado']) ?>
      <?= insigniaArchivado($e['modo_archivado']) ?>
      · Base <?= e($e['base_nombre']) ?>
      <?= $e['responsable'] ? ' · Responsable: ' . e($e['responsable']) : '' ?>
    </p>
  </div>
  <div class="acciones">
    <?php if (puedeEditar()): ?>
      <a class="boton" href="estrategia-form.php?id=<?= $id ?>">Editar</a>
      <form method="post" style="display:inline">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="<?= $e['estado'] === 'activa' ? 'desactivar' : 'activar' ?>">
        <button class="boton" type="submit"><?= $e['estado'] === 'activa' ? 'Desactivar' : 'Activar' ?></button>
      </form>
    <?php endif; ?>
    <?php if (esAdmin()): ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('¿Eliminar la estrategia y todo su historial?')">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="eliminar">
        <button class="boton peligro" type="submit">Eliminar</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?= $error   ? aviso('error', $error)   : '' ?>
<?= $mensaje ? aviso('exito', $mensaje) : '' ?>

<?php if ($e['descripcion']): ?>
  <p><?= nl2br(e($e['descripcion'])) ?></p>
<?php endif; ?>

<!-- ===================== VALIDACIÓN ===================== -->
<div class="panel">
  <h2>Validación de la estrategia</h2>
  <?php if (!$avisos): ?>
    <?= aviso('exito', 'La estrategia es coherente y no presenta observaciones.') ?>
  <?php else: ?>
    <?php foreach ($avisos as $a): ?>
      <div class="aviso <?= $a['nivel'] === 'error' ? 'error' : e($a['nivel']) ?>">
        <span class="titulo"><?= e(ucfirst($a['nivel'])) ?></span>
        <p><?= e($a['mensaje']) ?></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="nota">Las advertencias y recomendaciones no bloquean nada: la decisión queda con el
     administrador. Solo los errores impiden generar el script.</p>
</div>

<div class="rejilla c2">

  <!-- ===================== CONFIGURACIÓN ===================== -->
  <div class="panel">
    <h2>Qué · Cómo · Cuándo</h2>
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
    <h2>Estado de la automatización</h2>
    <div class="tabla-envoltura">
      <table>
        <tbody>
          <tr><th>Script</th><td>
            <?php if ((int) $e['aprobado'] === 1): ?>
              <span class="insignia ok">Aprobado</span>
              por <?= e($e['aprobado_por']) ?> el <?= formatoFecha($e['aprobado_en']) ?>
            <?php elseif ($e['script_rman']): ?>
              <span class="insignia warn">Generado, sin aprobar</span>
            <?php else: ?>
              <span class="insignia neutra">Sin generar</span>
            <?php endif; ?>
          </td></tr>
          <tr><th>Programación</th><td><?= e(Programacion::describir($e)) ?></td></tr>
          <tr><th>Próxima ejecución</th><td class="mono"><?= formatoFecha($e['proxima_ejecucion']) ?></td></tr>
          <tr><th>Última ejecución</th><td class="mono"><?= formatoFecha($e['ultima_ejecucion']) ?></td></tr>
          <tr><th>Ventana</th><td><?= $e['ventana_minutos'] ? (int) $e['ventana_minutos'] . ' minutos' : '—' ?></td></tr>
          <tr><th>Destino</th><td class="mono"><?= e($e['destino'] ?: 'Fast Recovery Area') ?></td></tr>
        </tbody>
      </table>
    </div>

    <?php if ($e['estado'] === 'activa' && (int) $e['aprobado'] === 1): ?>
      <?= aviso('exito', 'La estrategia se ejecutará sola en la próxima fecha programada, ' .
                'siempre que el runner esté activo en el servidor.') ?>
    <?php elseif ($e['estado'] === 'activa'): ?>
      <?= aviso('advertencia', 'La estrategia está activa pero su script no está aprobado, ' .
                'así que no se ejecutará automáticamente.') ?>
    <?php endif; ?>
  </div>

</div>

<!-- ===================== SCRIPT RMAN ===================== -->
<div class="panel">
  <h2>Script RMAN</h2>

  <div class="botonera" style="margin-bottom:1rem">
    <?php if (puedeEditar()): ?>
      <form method="post" style="display:inline">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="generar">
        <button class="boton primario" type="submit" <?= $hayErrores ? 'disabled' : '' ?>>
          <?= $e['script_rman'] ? 'Regenerar script' : 'Generar script' ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if (esAdmin() && $e['script_rman'] && (int) $e['aprobado'] === 0): ?>
      <form method="post" style="display:inline">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="aprobar">
        <button class="boton primario" type="submit">Aprobar este script</button>
      </form>
    <?php endif; ?>

    <?php if (esAdmin() && (int) $e['aprobado'] === 1): ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('¿Ejecutar el respaldo ahora sobre <?= e($e['base_nombre']) ?>?')">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="ejecutar">
        <button class="boton primario" type="submit">Ejecutar ahora</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="simular_fallo">
        <button class="boton" type="submit" title="Genera una ejecución fallida sin tocar la base">
          Simular un fallo
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($hayErrores): ?>
    <?= aviso('error', 'No se puede generar el script mientras la estrategia tenga errores de validación.') ?>
  <?php endif; ?>

  <?php if ($e['script_rman']): ?>
    <p class="nota">Generado el <?= formatoFecha($e['script_generado_en']) ?>. Este es exactamente
       el conjunto de instrucciones que se enviará a RMAN.</p>
    <pre class="script"><?= e($e['script_rman']) ?></pre>
  <?php else: ?>
    <div class="vacio"><p>Todavía no se ha generado el script para esta estrategia.</p></div>
  <?php endif; ?>
</div>

<!-- ===================== EVIDENCIA ===================== -->
<div class="panel">
  <h2>Evidencia de ejecución</h2>
  <?php if (!$ejecuciones): ?>
    <div class="vacio"><p>Esta estrategia no se ha ejecutado todavía.</p></div>
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

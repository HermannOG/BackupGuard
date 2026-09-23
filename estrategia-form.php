<?php
/**
 * Construcción de una estrategia: la interfaz que reemplaza al script RMAN
 * escrito a mano. Está organizada en los cuatro bloques del modelo:
 * general, QUÉ, CÓMO, CUÁNDO y destino.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/oracle.php';
require_once __DIR__ . '/includes/Programacion.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereEdicion();

$repo = new EstrategiaRepository();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$error = null;

$bases = $repo->listarBases(true);
if (!$bases) {
    $tituloPagina = 'Nueva estrategia';
    require_once __DIR__ . '/includes/header.php';
    require_once __DIR__ . '/includes/navbar.php';
    echo '<h1>Nueva estrategia</h1>';
    echo aviso('advertencia', 'Primero registrá al menos una base de datos Oracle.');
    echo '<a class="boton primario" href="bases-datos.php">Registrar una base</a>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Valores por defecto de una estrategia nueva.
$e = [
    'nombre' => '', 'descripcion' => '', 'base_datos_id' => $bases[0]['id'],
    'responsable' => $_SESSION['usuario']['nombre_completo'] ?? '', 'prioridad' => 'media',
    'justificacion_prioridad' => '', 'estado' => 'inactiva',
    'alcance' => 'base_completa', 'incluir_controlfile' => 1, 'incluir_spfile' => 1,
    'incluir_archivelogs' => 0, 'borrar_archivelogs' => 0,
    'tipo_respaldo' => 'incremental_0', 'modalidad' => null, 'comprimido' => 1,
    'paralelismo' => 2, 'retencion_dias' => 14, 'verificar_respaldo' => 1,
    'fecha_inicio' => date('Y-m-d'), 'hora' => '23:00', 'frecuencia' => 'diaria',
    'dias_semana' => '', 'dia_mes' => null, 'ventana_minutos' => 120, 'destino' => '',
];
$objetosActuales = [];

if ($id) {
    $existente = $repo->obtener($id);
    if (!$existente) { header('Location: estrategias.php'); exit; }
    $e = array_merge($e, $existente);
    $objetosActuales = array_column($repo->objetos($id), 'nombre');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $datos = [
        'nombre'        => trim($_POST['nombre'] ?? ''),
        'descripcion'   => trim($_POST['descripcion'] ?? ''),
        'base_datos_id' => (int) ($_POST['base_datos_id'] ?? 0),
        'responsable'   => trim($_POST['responsable'] ?? ''),
        'prioridad'     => $_POST['prioridad'] ?? 'media',
        'justificacion_prioridad' => trim($_POST['justificacion_prioridad'] ?? ''),
        'estado'        => $_POST['estado'] ?? 'inactiva',

        'alcance'             => $_POST['alcance'] ?? 'base_completa',
        'incluir_controlfile' => isset($_POST['incluir_controlfile']) ? 1 : 0,
        'incluir_spfile'      => isset($_POST['incluir_spfile']) ? 1 : 0,
        'incluir_archivelogs' => isset($_POST['incluir_archivelogs']) ? 1 : 0,
        'borrar_archivelogs'  => isset($_POST['borrar_archivelogs']) ? 1 : 0,

        'tipo_respaldo'     => $_POST['tipo_respaldo'] ?? 'completo',
        'modalidad'         => ($_POST['tipo_respaldo'] ?? '') === 'incremental_1'
                                ? ($_POST['modalidad'] ?? 'diferencial') : null,
        'comprimido'        => isset($_POST['comprimido']) ? 1 : 0,
        'paralelismo'       => (int) ($_POST['paralelismo'] ?? 1),
        'retencion_dias'    => $_POST['retencion_dias'] !== '' ? (int) $_POST['retencion_dias'] : null,
        'verificar_respaldo'=> isset($_POST['verificar_respaldo']) ? 1 : 0,

        'fecha_inicio'    => $_POST['fecha_inicio'] ?: null,
        'hora'            => $_POST['hora'] ?: null,
        'frecuencia'      => $_POST['frecuencia'] ?? 'diaria',
        'dias_semana'     => !empty($_POST['dias_semana']) ? implode(',', array_map('intval', $_POST['dias_semana'])) : null,
        'dia_mes'         => $_POST['dia_mes'] !== '' ? (int) $_POST['dia_mes'] : null,
        'ventana_minutos' => $_POST['ventana_minutos'] !== '' ? (int) $_POST['ventana_minutos'] : null,
        'destino'         => trim($_POST['destino'] ?? ''),

        'objetos' => array_filter(array_map('trim', explode("\n", $_POST['objetos'] ?? ''))),
    ];

    if ($datos['nombre'] === '') {
        $error = 'La estrategia necesita un nombre.';
        $e = array_merge($e, $datos);
        $objetosActuales = $datos['objetos'];
    } else {
        try {
            $nuevoId = $repo->guardar($datos, $id);
            bitacora($id ? 'editar_estrategia' : 'crear_estrategia', 'estrategias', $nuevoId, $datos['nombre']);
            header('Location: estrategia-detalle.php?id=' . $nuevoId . '&guardada=1');
            exit;
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
            $e = array_merge($e, $datos);
            $objetosActuales = $datos['objetos'];
        }
    }
}

// Contexto de la base elegida: sirve para mostrar el aviso de archivado
// y para sugerir los tablespaces existentes.
$baseElegida = $repo->obtenerBase((int) $e['base_datos_id']);
$diasSel = Programacion::diasSemana($e);

$tituloPagina = $id ? 'Editar estrategia' : 'Nueva estrategia';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1><?= $id ? 'Editar estrategia' : 'Nueva estrategia' ?></h1>
    <p class="sub">Definí qué respaldar, cómo respaldarlo y cuándo ejecutarlo. El script RMAN se
       genera después, y no se ejecuta hasta que un administrador lo apruebe.</p>
  </div>
</div>

<?= $error ? aviso('error', $error) : '' ?>

<?php if ($id && (int) ($e['aprobado'] ?? 0) === 1): ?>
  <?= aviso('advertencia', 'Esta estrategia ya tiene un script aprobado. Al guardar los cambios, ' .
            'la aprobación se anula y habrá que generar y revisar el script otra vez.') ?>
<?php endif; ?>

<?php if ($baseElegida && $baseElegida['modo_archivado'] === 'NOARCHIVELOG'): ?>
  <?= aviso('advertencia',
      'La base seleccionada está en modo NOARCHIVELOG. Las posibilidades de recuperación son más ' .
      'limitadas: no podrás recuperar hasta un punto en el tiempo ni incluir archived redo logs.',
      'Advertencia') ?>
<?php elseif ($baseElegida && $baseElegida['modo_archivado'] === 'ARCHIVELOG' && (int) $e['incluir_archivelogs'] === 0): ?>
  <?= aviso('recomendacion',
      'La base seleccionada está en modo ARCHIVELOG. Considere incorporar el respaldo periódico de ' .
      'los archived redo logs dentro de la estrategia para mejorar las posibilidades de recuperación.',
      'Recomendación') ?>
<?php endif; ?>

<form method="post">
  <?= csrfCampo() ?>

  <!-- ============== INFORMACIÓN GENERAL ============== -->
  <fieldset>
    <legend>Información general</legend>

    <div class="rejilla c2">
      <div class="campo">
        <label for="nombre">Nombre de la estrategia</label>
        <input type="text" id="nombre" name="nombre" required
               value="<?= e($e['nombre']) ?>" placeholder="Producción diaria">
      </div>
      <div class="campo">
        <label for="base_datos_id">Base de datos</label>
        <select id="base_datos_id" name="base_datos_id" onchange="this.form.submit()">
          <?php foreach ($bases as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $e['base_datos_id'] === (int) $b['id'] ? 'selected' : '' ?>>
              <?= e($b['nombre']) ?> — <?= e($b['ambiente']) ?> (<?= e($b['modo_archivado']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="campo">
      <label for="descripcion">Descripción</label>
      <textarea id="descripcion" name="descripcion" rows="2"
                placeholder="Qué protege esta estrategia y por qué"><?= e($e['descripcion']) ?></textarea>
    </div>

    <div class="rejilla c3">
      <div class="campo">
        <label for="responsable">Responsable</label>
        <input type="text" id="responsable" name="responsable" value="<?= e($e['responsable']) ?>">
      </div>
      <div class="campo">
        <label for="prioridad">Prioridad de la información</label>
        <select id="prioridad" name="prioridad">
          <option value="alta"  <?= $e['prioridad'] === 'alta'  ? 'selected' : '' ?>>Alta — crítica para la operación</option>
          <option value="media" <?= $e['prioridad'] === 'media' ? 'selected' : '' ?>>Media — admite mayores tiempos</option>
          <option value="baja"  <?= $e['prioridad'] === 'baja'  ? 'selected' : '' ?>>Baja — reconstruible</option>
        </select>
      </div>
      <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
          <option value="inactiva" <?= $e['estado'] === 'inactiva' ? 'selected' : '' ?>>Inactiva</option>
          <option value="activa"   <?= $e['estado'] === 'activa'   ? 'selected' : '' ?>>Activa</option>
        </select>
        <div class="ayuda">Solo las activas y aprobadas se ejecutan solas.</div>
      </div>
    </div>

    <div class="campo">
      <label for="justificacion_prioridad">Criterio con que se asignó la prioridad</label>
      <textarea id="justificacion_prioridad" name="justificacion_prioridad" rows="2"
                placeholder="Ej.: soporta matrícula en línea; una caída detiene la operación"><?= e($e['justificacion_prioridad']) ?></textarea>
    </div>
  </fieldset>

  <!-- ============== QUÉ RESPALDAR ============== -->
  <fieldset>
    <legend>Qué respaldar <span class="paso">— alcance de la estrategia</span></legend>

    <div class="campo">
      <label for="alcance">Alcance</label>
      <select id="alcance" name="alcance">
        <option value="base_completa" <?= $e['alcance'] === 'base_completa' ? 'selected' : '' ?>>Base de datos completa</option>
        <option value="tablespaces"   <?= $e['alcance'] === 'tablespaces'   ? 'selected' : '' ?>>Tablespaces específicos</option>
        <option value="datafiles"     <?= $e['alcance'] === 'datafiles'     ? 'selected' : '' ?>>Datafiles específicos</option>
      </select>
    </div>

    <div class="campo">
      <label for="objetos">Tablespaces o datafiles (uno por línea)</label>
      <textarea id="objetos" name="objetos" rows="4"
                placeholder="USERS&#10;SYSAUX"><?= e(implode("\n", $objetosActuales)) ?></textarea>
      <div class="ayuda">Solo se usa cuando el alcance no es la base completa. Para datafiles podés
         escribir el número (4) o la ruta completa.</div>
    </div>

    <div class="rejilla c2">
      <div>
        <div class="check">
          <input type="checkbox" id="incluir_controlfile" name="incluir_controlfile" <?= (int) $e['incluir_controlfile'] ? 'checked' : '' ?>>
          <label for="incluir_controlfile">Incluir control file</label>
        </div>
        <div class="check">
          <input type="checkbox" id="incluir_spfile" name="incluir_spfile" <?= (int) $e['incluir_spfile'] ? 'checked' : '' ?>>
          <label for="incluir_spfile">Incluir SPFILE</label>
        </div>
      </div>
      <div>
        <div class="check">
          <input type="checkbox" id="incluir_archivelogs" name="incluir_archivelogs" <?= (int) $e['incluir_archivelogs'] ? 'checked' : '' ?>>
          <label for="incluir_archivelogs">Incluir archived redo logs
            <span class="ayuda">Requiere que la base esté en ARCHIVELOG.</span></label>
        </div>
        <div class="check">
          <input type="checkbox" id="borrar_archivelogs" name="borrar_archivelogs" <?= (int) $e['borrar_archivelogs'] ? 'checked' : '' ?>>
          <label for="borrar_archivelogs">Borrarlos tras respaldarlos (DELETE INPUT)</label>
        </div>
      </div>
    </div>
  </fieldset>

  <!-- ============== CÓMO RESPALDAR ============== -->
  <fieldset>
    <legend>Cómo respaldar <span class="paso">— tipo y parámetros</span></legend>

    <div class="rejilla c2">
      <div class="campo">
        <label for="tipo_respaldo">Tipo de respaldo</label>
        <select id="tipo_respaldo" name="tipo_respaldo">
          <option value="completo"      <?= $e['tipo_respaldo'] === 'completo'      ? 'selected' : '' ?>>Completo</option>
          <option value="incremental_0" <?= $e['tipo_respaldo'] === 'incremental_0' ? 'selected' : '' ?>>Incremental nivel 0 (base de la estrategia)</option>
          <option value="incremental_1" <?= $e['tipo_respaldo'] === 'incremental_1' ? 'selected' : '' ?>>Incremental nivel 1</option>
        </select>
      </div>
      <div class="campo">
        <label for="modalidad">Modalidad del nivel 1</label>
        <select id="modalidad" name="modalidad">
          <option value="diferencial" <?= ($e['modalidad'] ?? '') === 'diferencial' ? 'selected' : '' ?>>Diferencial — cambios desde el último incremental</option>
          <option value="acumulativo" <?= ($e['modalidad'] ?? '') === 'acumulativo' ? 'selected' : '' ?>>Acumulativo — cambios desde el último nivel 0</option>
        </select>
        <div class="ayuda">El diferencial ocupa menos y tarda menos; el acumulativo simplifica la recuperación.</div>
      </div>
    </div>

    <div class="rejilla c3">
      <div class="campo">
        <label for="paralelismo">Canales en paralelo</label>
        <input type="number" id="paralelismo" name="paralelismo" min="1" max="8" value="<?= (int) $e['paralelismo'] ?>">
      </div>
      <div class="campo">
        <label for="retencion_dias">Retención (días)</label>
        <input type="number" id="retencion_dias" name="retencion_dias" min="1"
               value="<?= $e['retencion_dias'] !== null ? (int) $e['retencion_dias'] : '' ?>">
        <div class="ayuda">Vacío = sin política de retención en el script.</div>
      </div>
      <div class="campo">
        <label for="ventana_minutos">Ventana de respaldo (minutos)</label>
        <input type="number" id="ventana_minutos" name="ventana_minutos" min="1"
               value="<?= $e['ventana_minutos'] !== null ? (int) $e['ventana_minutos'] : '' ?>">
        <div class="ayuda">Si la ejecución la excede, se marca con advertencia.</div>
      </div>
    </div>

    <div class="check">
      <input type="checkbox" id="comprimido" name="comprimido" <?= (int) $e['comprimido'] ? 'checked' : '' ?>>
      <label for="comprimido">Comprimir el respaldo (AS COMPRESSED BACKUPSET)</label>
    </div>
    <div class="check">
      <input type="checkbox" id="verificar_respaldo" name="verificar_respaldo" <?= (int) $e['verificar_respaldo'] ? 'checked' : '' ?>>
      <label for="verificar_respaldo">Verificar el respaldo después de crearlo
        <span class="ayuda">Agrega VALIDATE y RESTORE ... VALIDATE: comprueba que sirve para restaurar.</span></label>
    </div>
  </fieldset>

  <!-- ============== CUÁNDO RESPALDAR ============== -->
  <fieldset>
    <legend>Cuándo respaldar <span class="paso">— programación</span></legend>

    <div class="rejilla c3">
      <div class="campo">
        <label for="fecha_inicio">Fecha de inicio</label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= e($e['fecha_inicio']) ?>">
      </div>
      <div class="campo">
        <label for="hora">Hora</label>
        <input type="time" id="hora" name="hora" value="<?= e(substr((string) $e['hora'], 0, 5)) ?>">
      </div>
      <div class="campo">
        <label for="frecuencia">Frecuencia</label>
        <select id="frecuencia" name="frecuencia">
          <option value="unica"   <?= $e['frecuencia'] === 'unica'   ? 'selected' : '' ?>>Una sola vez</option>
          <option value="diaria"  <?= $e['frecuencia'] === 'diaria'  ? 'selected' : '' ?>>Diaria</option>
          <option value="semanal" <?= $e['frecuencia'] === 'semanal' ? 'selected' : '' ?>>Semanal</option>
          <option value="mensual" <?= $e['frecuencia'] === 'mensual' ? 'selected' : '' ?>>Mensual</option>
        </select>
      </div>
    </div>

    <div class="rejilla c2">
      <div class="campo">
        <label>Días de ejecución (frecuencia semanal)</label>
        <div style="display:flex;flex-wrap:wrap;gap:.9rem">
          <?php foreach (Programacion::DIAS as $num => $nombreDia): ?>
            <label class="check" style="margin:0">
              <input type="checkbox" name="dias_semana[]" value="<?= $num ?>"
                     <?= in_array($num, $diasSel, true) ? 'checked' : '' ?>>
              <span><?= substr($nombreDia, 0, 3) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="campo">
        <label for="dia_mes">Día del mes (frecuencia mensual)</label>
        <input type="number" id="dia_mes" name="dia_mes" min="1" max="31"
               value="<?= $e['dia_mes'] !== null ? (int) $e['dia_mes'] : '' ?>">
      </div>
    </div>
  </fieldset>

  <!-- ============== DESTINO ============== -->
  <fieldset>
    <legend>Destino</legend>
    <div class="campo">
      <label for="destino">Carpeta de destino</label>
      <input type="text" id="destino" name="destino" value="<?= e($e['destino']) ?>"
             placeholder="/u01/backup/orcl  (vacío = Fast Recovery Area)">
      <div class="ayuda">Si lo dejás vacío, el respaldo va a la Fast Recovery Area configurada en la base
         y el script se genera sin cláusula FORMAT.</div>
    </div>
  </fieldset>

  <div class="botonera">
    <button type="submit" class="boton primario">Guardar estrategia</button>
    <a class="boton" href="<?= $id ? 'estrategia-detalle.php?id=' . (int) $id : 'estrategias.php' ?>">Cancelar</a>
  </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

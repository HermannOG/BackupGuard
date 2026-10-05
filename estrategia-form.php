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
    echo '<div class="encabezado"><div><h1>Nueva estrategia</h1></div></div>';
    echo '<div class="panel"><div class="vacio-guia">'
       . '<div class="vacio-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7" ry="2.5"/>'
       . '<path d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13"/><path d="M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/></svg></div>'
       . '<h3>Primero necesitás una base de datos</h3>'
       . '<p>Una estrategia siempre protege una base Oracle concreta. Registrala y volvé aquí.</p>'
       . '<a class="boton primario" href="bases-datos.php">Registrar una base</a></div></div>';
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
    'dias_semana' => '', 'dia_mes' => null, 'ventana_minutos' => 120,
    'destino' => '',
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
        'retencion_dias'    => ($_POST['retencion_dias'] ?? '') !== '' ? (int) $_POST['retencion_dias'] : null,
        'verificar_respaldo'=> isset($_POST['verificar_respaldo']) ? 1 : 0,

        'fecha_inicio'    => ($_POST['fecha_inicio'] ?? '') ?: null,
        'hora'            => ($_POST['hora'] ?? '') ?: null,
        'frecuencia'      => $_POST['frecuencia'] ?? 'diaria',
        'dias_semana'     => !empty($_POST['dias_semana']) ? implode(',', array_map('intval', $_POST['dias_semana'])) : null,
        'dia_mes'         => ($_POST['dia_mes'] ?? '') !== '' ? (int) $_POST['dia_mes'] : null,
        'ventana_minutos' => ($_POST['ventana_minutos'] ?? '') !== '' ? (int) $_POST['ventana_minutos'] : null,
        'destino'         => trim($_POST['destino'] ?? ''),

        'objetos' => array_filter(array_map('trim', explode("\n", $_POST['objetos'] ?? ''))),
    ];

    // Catálogo día-hora (frecuencia semanal): cada día marcado lleva sus
    // propias horas ("13:00" o "13:00, 17:00"); si se deja vacío, usa la
    // hora general.
    $datos['horarios'] = [];
    $horasInvalidas = [];
    foreach ($_POST['dias_semana'] ?? [] as $dia) {
        $dia = (int) $dia;
        $texto = trim((string) ($_POST['horas_dia'][$dia] ?? ''));
        $horas = $texto === '' ? [$datos['hora']] : array_map('trim', explode(',', $texto));
        foreach ($horas as $h) {
            if ($h !== null && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $h)) {
                $datos['horarios'][] = [$dia, sprintf('%05s', $h) . ':00'];
            } elseif ($h !== null && $h !== '') {
                $horasInvalidas[] = (Programacion::DIAS[$dia] ?? '?') . ': "' . $h . '"';
            }
        }
    }
    // La hora general queda como la más temprana del catálogo, para que las
    // vistas y alertas que solo leen "hora" sigan teniendo un valor.
    if ($datos['frecuencia'] === 'semanal' && $datos['horarios'] && !$datos['hora']) {
        $datos['hora'] = min(array_column($datos['horarios'], 1));
    }

    if ($datos['nombre'] === '' || $horasInvalidas) {
        $error = $horasInvalidas
            ? 'Horas no válidas (usá el formato 13:00): ' . implode(', ', $horasInvalidas) . '.'
            : 'La estrategia necesita un nombre.';
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

// Horas por día para rellenar el catálogo día-hora del formulario.
$horasPorDia = [];
if (isset($datos['horarios'])) {          // reintento tras un error: lo que se escribió
    foreach ($_POST['horas_dia'] ?? [] as $d => $texto) {
        $horasPorDia[(int) $d] = (string) $texto;
    }
} else {
    foreach ($e['horarios'] ?? [] as $h) {
        $horasPorDia[(int) $h['dia_semana']][] = substr((string) $h['hora'], 0, 5);
    }
    $horasPorDia = array_map(fn($hs) => implode(', ', $hs), $horasPorDia);
}

// Modo de archivado de cada base, para los avisos en vivo del formulario
// (antes, cambiar de base enviaba el formulario y guardaba la estrategia a medias).
$modosBase = [];
foreach ($bases as $b) {
    $modosBase[(int) $b['id']] = $b['modo_archivado'];
}
$modoActual = $baseElegida['modo_archivado'] ?? 'DESCONOCIDO';

$tituloPagina = $id ? 'Editar estrategia' : 'Nueva estrategia';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

/** Atajo: 'checked' si la condición se cumple. */
$chk = fn($cond) => $cond ? 'checked' : '';
?>

<div class="encabezado">
  <div>
    <h1><?= $id ? 'Editar estrategia' : 'Nueva estrategia' ?></h1>
    <p class="sub">Definí qué respaldar, cómo y cuándo. El script RMAN se genera después y no se ejecuta
       hasta que un administrador lo apruebe.</p>
  </div>
</div>

<?= guia(
    'Completá las cinco secciones y guardá. Abajo vas a ver un resumen en lenguaje natural de lo que armaste.',
    [
        ['titulo' => 'Qué respaldar',
         'texto'  => 'Elegí si se protege la base completa o solo algunos tablespaces o datafiles, y qué archivos de control se incluyen.'],
        ['titulo' => 'Cómo respaldar',
         'texto'  => 'El tipo de respaldo (completo o incremental), la compresión, la retención y si se verifica.'],
        ['titulo' => 'Cuándo respaldar',
         'texto'  => 'Desde qué fecha, a qué hora y con qué frecuencia se ejecuta automáticamente.'],
        ['titulo' => 'Guardar y revisar',
         'texto'  => 'Al guardar, BackupGuard <b>valida</b> la estrategia y construye el <b>script RMAN</b> para que lo revisés y aprobés.'],
    ],
    'Construir la estrategia desde un formulario, en lugar de escribir RMAN a mano, evita errores de sintaxis y '
  . 'olvidos (como no incluir el control file). Además, la herramienta revisa la configuración <b>antes</b> de '
  . 'generar nada, que es justamente lo que pide el enunciado: un proceso planificado y verificable.',
    !$id
) ?>

<?= $error ? aviso('error', $error) : '' ?>

<?php if ($id && (int) ($e['aprobado'] ?? 0) === 1): ?>
  <?= aviso('advertencia', 'Esta estrategia ya tiene un script aprobado. Al guardar los cambios, ' .
            'la aprobación se anula y habrá que generar y revisar el script otra vez.') ?>
<?php endif; ?>

<form method="post" id="formEstrategia" class="form-estrategia">
  <?= csrfCampo() ?>

  <!-- ============== 1. INFORMACIÓN GENERAL ============== -->
  <section class="panel bloque">
    <div class="bloque-titulo">
      <span class="seccion-num">1</span>
      <div><h2>Información general</h2><p>Qué es la estrategia, a qué base pertenece y quién responde por ella.</p></div>
    </div>

    <div class="rejilla c2">
      <div class="campo">
        <label for="nombre">Nombre de la estrategia <span class="req">*</span>
          <?= ayuda('Nombre', 'Un nombre que diga qué protege y con qué ritmo. Por ejemplo '
                  . '<span class="mono">Ventas — incremental diario</span>.') ?></label>
        <input type="text" id="nombre" name="nombre" required
               value="<?= e($e['nombre']) ?>" placeholder="Ventas — incremental diario">
      </div>
      <div class="campo">
        <label for="base_datos_id">Base de datos <span class="req">*</span>
          <?= ayuda('Base de datos', 'La instancia Oracle que esta estrategia protege. Entre paréntesis se ve su '
                  . 'modo de archivado; si dice <b>DESCONOCIDO</b>, verificala primero en '
                  . '<a href="bases-datos.php">Bases de datos</a>.') ?></label>
        <select id="base_datos_id" name="base_datos_id">
          <?php foreach ($bases as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $e['base_datos_id'] === (int) $b['id'] ? 'selected' : '' ?>>
              <?= e($b['nombre']) ?> — <?= e($b['ambiente']) ?> (<?= e($b['modo_archivado']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Avisos según el modo de archivado de la base elegida (se actualizan sin recargar) -->
    <div class="aviso advertencia" data-modo-aviso="NOARCHIVELOG" hidden>
      <span class="titulo">Advertencia</span>
      <p>La base de datos se encuentra en modo NOARCHIVELOG. Las posibilidades de recuperación son más limitadas:
         no podrás recuperar hasta un punto en el tiempo ni incluir archived redo logs. Revise la estrategia de
         respaldo y los requerimientos de recuperación antes de continuar.</p>
    </div>
    <div class="aviso recomendacion" data-modo-aviso="ARCHIVELOG" hidden>
      <span class="titulo">Recomendación</span>
      <p>La base de datos se encuentra en modo ARCHIVELOG. Considere incorporar el respaldo periódico de los
         archived redo logs dentro de la estrategia para mejorar las posibilidades de recuperación.</p>
    </div>
    <div class="aviso informacion" data-modo-aviso="DESCONOCIDO" hidden>
      <span class="titulo">Información</span>
      <p>Todavía no se conoce el modo de archivado de esta base. Podés armar la estrategia igual, pero conviene
         verificarla en <a href="bases-datos.php">Bases de datos</a> para recibir las advertencias correctas.</p>
    </div>

    <div class="campo">
      <label for="descripcion">Descripción</label>
      <textarea id="descripcion" name="descripcion" rows="2"
                placeholder="Qué protege esta estrategia y por qué"><?= e($e['descripcion']) ?></textarea>
    </div>

    <div class="rejilla c3">
      <div class="campo">
        <label for="responsable">Responsable
          <?= ayuda('Responsable', 'La persona que responde por esta estrategia: revisa sus resultados y atiende '
                  . 'sus alertas. Que tenga dueño es parte del control.') ?></label>
        <input type="text" id="responsable" name="responsable" value="<?= e($e['responsable']) ?>">
      </div>
      <div class="campo">
        <label for="prioridad">Prioridad de la información
          <?= ayuda('Prioridad', '<b>Alta</b>: información crítica para la continuidad de la operación.<br>'
                  . '<b>Media</b>: importante, pero su recuperación admite mayores tiempos.<br>'
                  . '<b>Baja</b>: menor impacto o se puede reconstruir.') ?></label>
        <select id="prioridad" name="prioridad">
          <option value="alta"  <?= $e['prioridad'] === 'alta'  ? 'selected' : '' ?>>Alta — crítica para la operación</option>
          <option value="media" <?= $e['prioridad'] === 'media' ? 'selected' : '' ?>>Media — admite mayores tiempos</option>
          <option value="baja"  <?= $e['prioridad'] === 'baja'  ? 'selected' : '' ?>>Baja — reconstruible</option>
        </select>
      </div>
      <div class="campo">
        <label for="estado">Estado
          <?= ayuda('Estado', 'Una estrategia <b>inactiva</b> nunca se ejecuta sola, aunque esté aprobada. '
                  . 'Para que corra automáticamente tiene que estar <b>activa y aprobada</b>.') ?></label>
        <select id="estado" name="estado">
          <option value="inactiva" <?= $e['estado'] === 'inactiva' ? 'selected' : '' ?>>Inactiva</option>
          <option value="activa"   <?= $e['estado'] === 'activa'   ? 'selected' : '' ?>>Activa</option>
        </select>
      </div>
    </div>

    <div class="campo">
      <label for="justificacion_prioridad">Criterio con que se asignó la prioridad
        <?= porque('¿Por qué justificar la prioridad?',
            'La prioridad decide qué se protege con más rigor. Dejar escrito el criterio permite que otra persona '
          . 'entienda la decisión y la revise después. El enunciado pide que el grupo defina esos criterios.') ?></label>
      <textarea id="justificacion_prioridad" name="justificacion_prioridad" rows="2"
                placeholder="Ej.: soporta la facturación en línea; una caída detiene la operación"><?= e($e['justificacion_prioridad']) ?></textarea>
    </div>
  </section>

  <!-- ============== 2. QUÉ RESPALDAR ============== -->
  <section class="panel bloque">
    <div class="bloque-titulo">
      <span class="seccion-num">2</span>
      <div><h2>Qué respaldar</h2><p>El alcance de la estrategia y los archivos que la acompañan.</p></div>
    </div>

    <div class="campo">
      <label for="alcance">Alcance
        <?= ayuda('Alcance', '<b>Base completa</b>: todos los datafiles. Es lo más seguro.<br>'
                . '<b>Tablespaces</b>: solo los que elijas, por ejemplo los de una aplicación.<br>'
                . '<b>Datafiles</b>: archivos físicos puntuales, para casos muy específicos.') ?></label>
      <select id="alcance" name="alcance">
        <option value="base_completa" <?= $e['alcance'] === 'base_completa' ? 'selected' : '' ?>>Base de datos completa</option>
        <option value="tablespaces"   <?= $e['alcance'] === 'tablespaces'   ? 'selected' : '' ?>>Tablespaces específicos</option>
        <option value="datafiles"     <?= $e['alcance'] === 'datafiles'     ? 'selected' : '' ?>>Datafiles específicos</option>
      </select>
    </div>

    <div class="campo" id="campoObjetos">
      <label for="objetos"><span id="etiquetaObjetos">Tablespaces</span> a respaldar (uno por línea) <span class="req">*</span>
        <?= ayuda('Tablespaces y datafiles', 'Escribí un nombre por línea. Para tablespaces: '
                . '<span class="mono">USERS</span>, <span class="mono">SYSAUX</span>. Para datafiles podés usar '
                . 'el número (<span class="mono">4</span>) o la ruta completa.') ?></label>
      <textarea id="objetos" name="objetos" rows="3"
                placeholder="USERS&#10;SYSAUX"><?= e(implode("\n", $objetosActuales)) ?></textarea>
    </div>

    <label class="etiqueta-grupo">Archivos que acompañan al respaldo</label>
    <div class="opciones-tarjeta">
      <label class="opcion">
        <input type="checkbox" id="incluir_controlfile" name="incluir_controlfile" <?= $chk((int) $e['incluir_controlfile']) ?>>
        <span>
          <strong>Control file <em class="etiqueta-rec">Recomendado</em></strong>
          <small>Describe la estructura física de la base. Sin él, restaurar es mucho más difícil.</small>
        </span>
      </label>
      <label class="opcion">
        <input type="checkbox" id="incluir_spfile" name="incluir_spfile" <?= $chk((int) $e['incluir_spfile']) ?>>
        <span>
          <strong>SPFILE <em class="etiqueta-rec">Recomendado</em></strong>
          <small>Los parámetros de arranque de la instancia. Permite levantarla igual que antes.</small>
        </span>
      </label>
      <label class="opcion" id="opcionArchivelogs">
        <input type="checkbox" id="incluir_archivelogs" name="incluir_archivelogs" <?= $chk((int) $e['incluir_archivelogs']) ?>>
        <span>
          <strong>Archived redo logs</strong>
          <small>Permiten recuperar hasta un punto exacto en el tiempo. Requieren modo ARCHIVELOG.</small>
          <small class="nota-bloqueo" hidden>No disponible: la base está en NOARCHIVELOG.</small>
        </span>
      </label>
    </div>

    <div class="check sub-opcion" id="filaBorrar">
      <input type="checkbox" id="borrar_archivelogs" name="borrar_archivelogs" <?= $chk((int) $e['borrar_archivelogs']) ?>>
      <label for="borrar_archivelogs">Borrar los archived logs del disco después de respaldarlos (DELETE INPUT)
        <?= ayuda('DELETE INPUT', 'Libera espacio en disco borrando los archived logs una vez que quedaron '
                . 'dentro del respaldo. Conviene combinarlo con la verificación del respaldo: si la copia '
                . 'estuviera dañada, el original ya no existiría.') ?></label>
    </div>
  </section>

  <!-- ============== 3. CÓMO RESPALDAR ============== -->
  <section class="panel bloque">
    <div class="bloque-titulo">
      <span class="seccion-num">3</span>
      <div><h2>Cómo respaldar</h2><p>El tipo de respaldo y los parámetros con que se ejecuta.</p></div>
    </div>

    <label class="etiqueta-grupo">Tipo de respaldo
      <?= porque('¿Cuál conviene?',
          '<b>Espacio y tiempo</b>: el completo y el nivel 0 copian todo; el nivel 1 solo lo que cambió, así que '
        . 'ocupa y tarda mucho menos.<br><br>'
        . '<b>Recuperación</b>: con incrementales hay que aplicar varias piezas en orden, por eso es más '
        . 'compleja.<br><br>'
        . 'Lo habitual: un <b>nivel 0 semanal</b> y un <b>nivel 1 diario</b>. Así se combina protección '
        . 'completa con respaldos diarios livianos.') ?></label>
    <div class="opciones-tarjeta tres">
      <label class="opcion">
        <input type="radio" name="tipo_respaldo" value="completo" <?= $chk($e['tipo_respaldo'] === 'completo') ?>>
        <span><strong>Completo</strong>
          <small>Copia todos los bloques usados. Simple de restaurar, pero no sirve como base para incrementales.</small></span>
      </label>
      <label class="opcion">
        <input type="radio" name="tipo_respaldo" value="incremental_0" <?= $chk($e['tipo_respaldo'] === 'incremental_0') ?>>
        <span><strong>Incremental nivel 0</strong>
          <small>También copia todo, pero sirve de <b>punto de partida</b> para los incrementales nivel 1.</small></span>
      </label>
      <label class="opcion">
        <input type="radio" name="tipo_respaldo" value="incremental_1" <?= $chk($e['tipo_respaldo'] === 'incremental_1') ?>>
        <span><strong>Incremental nivel 1</strong>
          <small>Copia solo los bloques que cambiaron. Necesita un nivel 0 previo.</small></span>
      </label>
    </div>

    <div id="bloqueModalidad">
      <label class="etiqueta-grupo">Modalidad del nivel 1
        <?= ayuda('Diferencial vs. acumulativo', '<b>Diferencial</b>: copia lo que cambió desde el último '
                . 'incremental (nivel 0 o 1). Cada respaldo es pequeño, pero para restaurar hay que aplicar '
                . '<b>todos</b> los diferenciales desde el nivel 0.<br><br><b>Acumulativo</b>: copia todo lo que '
                . 'cambió desde el último nivel 0. Cada respaldo crece con los días, pero para restaurar basta el '
                . 'nivel 0 y el <b>último</b> acumulativo.') ?></label>
      <div class="opciones-tarjeta">
        <label class="opcion">
          <input type="radio" name="modalidad" value="diferencial" <?= $chk(($e['modalidad'] ?? 'diferencial') !== 'acumulativo') ?>>
          <span><strong>Diferencial</strong>
            <small>Cambios desde el último incremental. Ocupa menos y tarda menos.</small></span>
        </label>
        <label class="opcion">
          <input type="radio" name="modalidad" value="acumulativo" <?= $chk(($e['modalidad'] ?? '') === 'acumulativo') ?>>
          <span><strong>Acumulativo</strong>
            <small>Cambios desde el último nivel 0. Recuperación más simple y rápida.</small></span>
        </label>
      </div>
    </div>

    <div class="rejilla c3 separada">
      <div class="campo">
        <label for="paralelismo">Canales en paralelo
          <?= ayuda('Canales', 'Cuántos procesos de RMAN trabajan a la vez (<span class="mono">ALLOCATE CHANNEL</span>). '
                  . 'Más canales terminan antes, pero cargan más el servidor. Para Oracle XE, 1 o 2 es suficiente.') ?></label>
        <input type="number" id="paralelismo" name="paralelismo" min="1" max="8" value="<?= (int) $e['paralelismo'] ?>">
      </div>
      <div class="campo">
        <label for="retencion_dias">Retención (días)
          <?= ayuda('Retención', 'Cuántos días hacia atrás se debe poder recuperar. RMAN marca como obsoletos y '
                  . 'borra (<span class="mono">DELETE OBSOLETE</span>) los respaldos que ya no hacen falta para esa '
                  . 'ventana. Si lo dejás vacío, no se borra nada automáticamente.') ?></label>
        <input type="number" id="retencion_dias" name="retencion_dias" min="1"
               value="<?= $e['retencion_dias'] !== null ? (int) $e['retencion_dias'] : '' ?>" placeholder="Sin política">
      </div>
      <div class="campo">
        <label for="ventana_minutos">Ventana de respaldo (min)
          <?= ayuda('Ventana de respaldo', 'El tiempo máximo que debería tardar el respaldo. Si una ejecución lo '
                  . 'supera, queda registrada <b>con advertencia</b>, porque podría estar afectando la operación.') ?></label>
        <input type="number" id="ventana_minutos" name="ventana_minutos" min="1"
               value="<?= $e['ventana_minutos'] !== null ? (int) $e['ventana_minutos'] : '' ?>">
      </div>
    </div>

    <div class="opciones-tarjeta">
      <label class="opcion">
        <input type="checkbox" id="comprimido" name="comprimido" <?= $chk((int) $e['comprimido']) ?>>
        <span><strong>Comprimir el respaldo</strong>
          <small>Ocupa bastante menos espacio a cambio de un poco más de CPU (<span class="mono">AS COMPRESSED BACKUPSET</span>).</small></span>
      </label>
      <label class="opcion">
        <input type="checkbox" id="verificar_respaldo" name="verificar_respaldo" <?= $chk((int) $e['verificar_respaldo']) ?>>
        <span><strong>Verificar que el respaldo sirve <em class="etiqueta-rec">Recomendado</em>
            <?= porque('¿Por qué verificar?',
                'Que RMAN termine sin errores no garantiza que el respaldo se pueda restaurar. '
              . '<span class="mono">RESTORE ... VALIDATE</span> lee las copias y comprueba que estén completas y '
              . 'sanas, sin restaurar nada. El enunciado pide no asumir que una estrategia es correcta solo porque '
              . 'el script corrió.') ?></strong>
          <small>Agrega <span class="mono">RESTORE ... VALIDATE</span>: comprueba que la copia se puede usar para restaurar.</small></span>
      </label>
    </div>
  </section>

  <!-- ============== 4. CUÁNDO RESPALDAR ============== -->
  <section class="panel bloque">
    <div class="bloque-titulo">
      <span class="seccion-num">4</span>
      <div><h2>Cuándo respaldar</h2><p>La programación con que el ejecutor automático corre la estrategia.</p></div>
    </div>

    <div class="rejilla c3">
      <div class="campo">
        <label for="frecuencia">Frecuencia
          <?= ayuda('Frecuencia', '<b>Una sola vez</b>: en la fecha y hora indicadas.<br>'
                  . '<b>Diaria</b>: todos los días a la hora indicada.<br>'
                  . '<b>Semanal</b>: los días que marques, cada uno con su hora.<br>'
                  . '<b>Mensual</b>: un día fijo del mes.') ?></label>
        <select id="frecuencia" name="frecuencia">
          <option value="unica"   <?= $e['frecuencia'] === 'unica'   ? 'selected' : '' ?>>Una sola vez</option>
          <option value="diaria"  <?= $e['frecuencia'] === 'diaria'  ? 'selected' : '' ?>>Diaria</option>
          <option value="semanal" <?= $e['frecuencia'] === 'semanal' ? 'selected' : '' ?>>Semanal</option>
          <option value="mensual" <?= $e['frecuencia'] === 'mensual' ? 'selected' : '' ?>>Mensual</option>
        </select>
      </div>
      <div class="campo">
        <label for="fecha_inicio">Fecha de inicio
          <?= ayuda('Fecha de inicio', 'A partir de qué día empieza a regir la programación.') ?></label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= e($e['fecha_inicio']) ?>">
      </div>
      <div class="campo">
        <label for="hora"><span id="etiquetaHora">Hora</span>
          <?= ayuda('Hora', 'Conviene una hora de poca actividad, como la noche, para no afectar a los usuarios. '
                  . 'En la frecuencia semanal funciona como hora por defecto cuando un día no tiene una hora propia.') ?></label>
        <input type="time" id="hora" name="hora" value="<?= e(substr((string) $e['hora'], 0, 5)) ?>">
      </div>
    </div>

    <div class="campo" id="bloqueSemanal">
      <label>Días y horas de ejecución
        <?= ayuda('Catálogo día-hora', 'Marcá los días en que se ejecuta. Si un día necesita otra hora, escribila '
                . 'en su casilla, o varias separadas por coma (<span class="mono">13:00, 17:00</span>). Vacío = la hora '
                . 'por defecto.') ?></label>
      <div class="dias-grid">
        <?php foreach (Programacion::DIAS as $num => $nombreDia): ?>
          <div class="dia-fila">
            <label class="dia-check">
              <input type="checkbox" name="dias_semana[]" value="<?= $num ?>" <?= $chk(in_array($num, $diasSel, true)) ?>>
              <span><?= e($nombreDia) ?></span>
            </label>
            <input type="text" name="horas_dia[<?= $num ?>]" inputmode="numeric"
                   value="<?= e($horasPorDia[$num] ?? '') ?>" placeholder="hora por defecto"
                   aria-label="Horas del <?= e($nombreDia) ?>">
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rejilla c3" id="bloqueMensual">
      <div class="campo">
        <label for="dia_mes">Día del mes
          <?= ayuda('Día del mes', 'Del 1 al 31. Si el mes no tiene ese día (por ejemplo, 31 en abril), se ejecuta '
                  . 'el último día del mes.') ?></label>
        <input type="number" id="dia_mes" name="dia_mes" min="1" max="31"
               value="<?= $e['dia_mes'] !== null ? (int) $e['dia_mes'] : '' ?>">
      </div>
    </div>
  </section>

  <!-- ============== 5. DESTINO ============== -->
  <section class="panel bloque">
    <div class="bloque-titulo">
      <span class="seccion-num">5</span>
      <div><h2>Destino</h2><p>Dónde se guardan los archivos del respaldo.</p></div>
    </div>

    <div class="campo">
      <label for="destino">Carpeta de destino
        <?= ayuda('Carpeta de destino', 'Una ruta completa, como <span class="mono">C:\BackupGuard_RMAN\XE</span>. '
                . 'Si la dejás vacía, RMAN utiliza la <b>Fast Recovery Area</b> configurada en Oracle.<br><br>'
                . 'Separar la copia de los archivos originales ayuda a reducir el riesgo de perder ambos ante una falla.') ?></label>
      <input type="text" id="destino" name="destino" value="<?= e($e['destino']) ?>"
             placeholder="Vacío = Fast Recovery Area">
    </div>
  </section>

  <!-- ============== RESUMEN + GUARDAR ============== -->
  <div class="barra-guardar">
    <div class="resumen">
      <span class="resumen-etiqueta">Resumen</span>
      <p id="resumenTexto">—</p>
    </div>
    <div class="botonera">
      <a class="boton" href="<?= $id ? 'estrategia-detalle.php?id=' . (int) $id : 'estrategias.php' ?>">Cancelar</a>
      <button type="submit" class="boton primario">Guardar estrategia</button>
    </div>
  </div>
</form>

<script>
(function () {
  var MODOS = <?= json_encode($modosBase) ?>;
  var DIAS = <?= json_encode(Programacion::DIAS) ?>;
  var f = document.getElementById('formEstrategia');
  var $ = function (sel) { return f.querySelector(sel); };
  var val = function (name) {
    var el = f.querySelector('[name="' + name + '"]:checked') || f.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  };

  function mostrar(el, si) {
    if (!el) return;
    el.hidden = !si;
    el.querySelectorAll('input, textarea, select').forEach(function (i) { i.disabled = !si; });
  }

  function actualizar() {
    // --- Avisos por modo de archivado
    var modo = MODOS[val('base_datos_id')] || 'DESCONOCIDO';
    var archOn = $('#incluir_archivelogs').checked;
    f.querySelectorAll('[data-modo-aviso]').forEach(function (a) {
      var m = a.getAttribute('data-modo-aviso');
      a.hidden = !(m === modo && !(m === 'ARCHIVELOG' && archOn));
    });

    // --- Archived logs: bloqueados en NOARCHIVELOG
    var noArch = modo === 'NOARCHIVELOG';
    var chkArch = $('#incluir_archivelogs');
    chkArch.disabled = noArch;
    if (noArch) chkArch.checked = false;
    $('#opcionArchivelogs').classList.toggle('bloqueada', noArch);
    $('#opcionArchivelogs .nota-bloqueo').hidden = !noArch;
    archOn = chkArch.checked;
    mostrar($('#filaBorrar'), archOn);

    // --- Alcance
    var alcance = val('alcance');
    mostrar($('#campoObjetos'), alcance !== 'base_completa');
    $('#etiquetaObjetos').textContent = alcance === 'datafiles' ? 'Datafiles' : 'Tablespaces';

    // --- Modalidad solo para nivel 1
    var tipo = val('tipo_respaldo');
    mostrar($('#bloqueModalidad'), tipo === 'incremental_1');

    // --- Programación
    var frec = val('frecuencia');
    mostrar($('#bloqueSemanal'), frec === 'semanal');
    mostrar($('#bloqueMensual'), frec === 'mensual');
    $('#etiquetaHora').textContent = frec === 'semanal' ? 'Hora por defecto' : 'Hora';
    f.querySelectorAll('.dia-fila').forEach(function (fila) {
      var c = fila.querySelector('input[type=checkbox]');
      var t = fila.querySelector('input[type=text]');
      if (frec === 'semanal') t.disabled = !c.checked;
      fila.classList.toggle('activa', c.checked);
    });

    resumen(alcance, tipo, frec);
  }

  function resumen(alcance, tipo, frec) {
    var objetos = ($('#objetos').value || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    var que = alcance === 'base_completa' ? 'la base completa'
            : (alcance === 'tablespaces' ? 'los tablespaces ' : 'los datafiles ') + (objetos.length ? objetos.join(', ') : '(sin indicar)');
    var extras = [];
    if ($('#incluir_controlfile').checked) extras.push('control file');
    if ($('#incluir_spfile').checked) extras.push('SPFILE');
    if ($('#incluir_archivelogs').checked) extras.push('archived logs');
    if (extras.length) que += ' + ' + extras.join(', ');

    var como = { completo: 'un respaldo completo', incremental_0: 'un incremental nivel 0',
                 incremental_1: 'un incremental nivel 1 ' + val('modalidad') }[tipo] || 'un respaldo';
    if ($('#comprimido').checked) como += ' comprimido';

    var hora = $('#hora').value || '--:--';
    var cuando;
    if (frec === 'unica') cuando = 'una sola vez el ' + ($('#fecha_inicio').value || '(fecha)') + ' a las ' + hora;
    else if (frec === 'diaria') cuando = 'todos los días a las ' + hora;
    else if (frec === 'mensual') cuando = 'el día ' + ($('#dia_mes').value || '?') + ' de cada mes a las ' + hora;
    else {
      var dias = [];
      f.querySelectorAll('input[name="dias_semana[]"]:checked').forEach(function (c) { dias.push(DIAS[c.value]); });
      cuando = dias.length ? 'los ' + dias.join(', ') + ' de cada semana'
                           : 'los días semanales (todavía sin marcar)';
    }

    var ret = $('#retencion_dias').value;
    var texto = 'Respalda ' + que + ' con ' + como + ', ' + cuando + '.';
    if (ret) texto += ' Conserva lo necesario para recuperar los últimos ' + ret + ' días.';
    var destino = ($('#destino').value || '').trim();
    texto += ' Se guarda ' + (destino ? 'en ' + destino : 'en la Fast Recovery Area') + '.';
    if ($('#verificar_respaldo').checked) texto += ' Cada copia se verifica después de crearla.';
    document.getElementById('resumenTexto').textContent = texto;
  }

  f.addEventListener('change', actualizar);
  f.addEventListener('input', actualizar);
  actualizar();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

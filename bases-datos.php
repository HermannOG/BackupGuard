<?php
/**
 * Registro de las bases Oracle que se van a respaldar.
 * Al verificar la conexión, la herramienta lee el modo de archivado y lo
 * guarda: de ahí salen la advertencia de NOARCHIVELOG y la recomendación
 * de incluir los archived redo logs. La herramienta nunca cambia el modo.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/oracle.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$mensaje = null;
$error = null;
$contexto = null;
$verificadaId = null;   // base que se acaba de verificar con éxito
$verificadaNombre = '';
$avisoVerificar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'registrar') {
            requiereAdmin();
            $nombre = trim($_POST['nombre'] ?? '');
            $usuario = trim($_POST['usuario'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($nombre === '' || $usuario === '' || $password === '') {
                throw new RuntimeException('Nombre, usuario y contraseña son obligatorios.');
            }

            $tns = trim($_POST['tns_alias'] ?? '');
            $host = trim($_POST['host'] ?? '');
            if ($tns === '' && ($host === '' || trim($_POST['service_name'] ?? '') === '')) {
                throw new RuntimeException(
                    'Indicá un alias TNS, o bien host, puerto y service name.'
                );
            }

            $id = $repo->guardarBase([
                'nombre'             => $nombre,
                'descripcion'        => trim($_POST['descripcion'] ?? ''),
                'ambiente'           => $_POST['ambiente'] ?? 'pruebas',
                'tns_alias'          => $tns,
                'host'               => $host,
                'puerto'             => (int) ($_POST['puerto'] ?? 1521) ?: null,
                'service_name'       => trim($_POST['service_name'] ?? ''),
                'usuario'            => $usuario,
                'password_enc'       => cifrar($password),
                // SYSBACKUP (mínimo privilegio) es la opción por defecto.
                'conectar_as_sysdba' => (($_POST['privilegio'] ?? 'sysbackup') === 'sysdba') ? 1 : 0,
            ]);

            bitacora('registrar_base', 'bases_datos', $id, $nombre);
            $mensaje = 'Base registrada. Ahora presioná «Verificar» en su fila para detectar el modo de archivado.';

        } elseif ($accion === 'verificar') {
            requiereAdmin();
            if (!oracleDisponible()) {
                $avisoVerificar = true;
                throw new RuntimeException('sin-oci8');
            }
            $id = (int) ($_POST['id'] ?? 0);
            $contexto = refrescarModoArchivado($id);
            $verificadaId = $id;
            foreach ($repo->listarBases(true) as $bv) {
                if ((int) $bv['id'] === $id) {
                    $verificadaNombre = $bv['nombre'] . ' (' . ($bv['tns_alias'] ?: $bv['host'] . ':' . $bv['puerto'] . '/' . $bv['service_name']) . ')';
                }
            }
            $mensaje = 'Conexión correcta con ' . $verificadaNombre . '. Modo de archivado detectado: '
                     . $contexto['modo_archivado'] . '.';

        } elseif ($accion === 'eliminar') {
            requiereAdmin();
            $id = (int) ($_POST['id'] ?? 0);
            $repo->eliminarBase($id);
            bitacora('baja_base', 'bases_datos', $id);
            $mensaje = 'La base se dio de baja. Su historial de respaldos se conserva.';
        }
    } catch (Throwable $ex) {
        if (!$avisoVerificar) {
            $error = $ex->getMessage();
        }
    }
}

$bases = $repo->listarBases(true);

$tituloPagina = 'Bases de datos';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Bases de datos</h1>
    <p class="sub">Las instancias Oracle que BackupGuard va a proteger. Todo empieza aquí.</p>
  </div>
  <?php if (esAdmin() && $bases): ?>
    <div class="acciones">
      <a class="boton primario" href="#registrar">+ Registrar base</a>
    </div>
  <?php endif; ?>
</div>

<?= guia(
    'Registrá la base Oracle y verificá su conexión antes de crear estrategias.',
    [
        ['titulo' => 'Registrá la base',
         'texto'  => 'Completá el formulario con los datos de conexión de tu instancia Oracle. '
                   . 'La contraseña se guarda cifrada.'],
        ['titulo' => 'Verificá la conexión',
         'texto'  => 'El botón <b>Verificar</b> se conecta a Oracle y lee el <b>modo de archivado</b>, '
                   . 'los tablespaces, los datafiles y el espacio de la Fast Recovery Area.'],
        ['titulo' => 'Creá estrategias para ella',
         'texto'  => 'Con la base verificada, andá a <a href="estrategias.php">Estrategias</a> y definí '
                   . 'qué respaldar, cómo y cuándo.'],
    ],
    'El modo de archivado determina qué tan completa puede ser la recuperación. Por eso BackupGuard lo lee '
  . 'antes de construir cualquier estrategia y advierte o recomienda según el caso, pero <b>nunca lo cambia</b>: '
  . 'esa decisión queda en manos del administrador, como pide el enunciado.',
    !$bases
) ?>

<?= $error   ? aviso('error', $error)    : '' ?>
<?= $avisoVerificar ? aviso('advertencia',
      'Oracle no está conectado en esta computadora, así que no se pudo leer el modo de archivado. '
    . 'La base quedó registrada y podés seguir trabajando en modo simulación.',
      'No se pudo verificar') : '' ?>
<?= $mensaje ? aviso('exito', $mensaje)  : '' ?>

<?php if ($contexto): ?>
  <div class="panel">
    <div class="verificada-titulo">
      <span class="verificada-check" aria-hidden="true">✓</span>
      <div>
        <h2>Verificación de <?= e($verificadaNombre ?: ($contexto['nombre_bd'] ?? 'la base')) ?></h2>
        <span class="muted">Conexión correcta · leído de Oracle el <?= formatoFecha(date('Y-m-d H:i:s')) ?></span>
      </div>
    </div>
    <div class="datos-fila">
      <div>
        <span class="dato-etiqueta">Modo de archivado
          <?= ayuda('Modo de archivado',
              '<b>ARCHIVELOG</b>: Oracle guarda una copia de cada redo log antes de reutilizarlo. Permite '
            . 'respaldar con la base abierta y recuperar hasta un punto exacto en el tiempo.<br><br>'
            . '<b>NOARCHIVELOG</b>: los redo logs se sobrescriben. Solo se puede volver al último respaldo '
            . 'completo, y respaldar exige tener la base cerrada o montada.') ?>
        </span>
        <?= insigniaArchivado($contexto['modo_archivado']) ?>
      </div>
      <div>
        <span class="dato-etiqueta">Estado</span>
        <span class="mono"><?= e($contexto['open_mode'] ?? '?') ?></span>
      </div>
      <div>
        <span class="dato-etiqueta">DBID
          <?= ayuda('DBID', 'Identificador único de la base. RMAN lo usa para reconocerla al restaurar.') ?>
        </span>
        <span class="mono"><?= e($contexto['dbid'] ?? '?') ?></span>
      </div>
    </div>

    <?php if ($contexto['modo_archivado'] === 'NOARCHIVELOG'): ?>
      <?= aviso('advertencia',
          'La base de datos se encuentra en modo NOARCHIVELOG. Las posibilidades de recuperación ' .
          'son más limitadas. Revise la estrategia de respaldo y los requerimientos de recuperación ' .
          'antes de continuar.', 'Advertencia') ?>
    <?php elseif ($contexto['modo_archivado'] === 'ARCHIVELOG'): ?>
      <?= aviso('recomendacion',
          'La base de datos se encuentra en modo ARCHIVELOG. Considere incorporar el respaldo ' .
          'periódico de los archived redo logs dentro de la estrategia para mejorar las ' .
          'posibilidades de recuperación.', 'Recomendación') ?>
    <?php endif; ?>

    <?php if (!empty($contexto['fra'])): ?>
      <?php $pct = (float) ($contexto['fra']['PCT_USADO'] ?? 0); ?>
      <p class="nota">Fast Recovery Area: <?= $pct ?>% usado
        (<?= formatoBytes((int) $contexto['fra']['SPACE_USED']) ?> de
         <?= formatoBytes((int) $contexto['fra']['SPACE_LIMIT']) ?>)
        <?= ayuda('Fast Recovery Area (FRA)',
            'Carpeta que Oracle reserva para respaldos y archived logs. Si se llena, los respaldos '
          . 'fallan con el error ORA-19809. Por eso se alerta desde el 85%.') ?></p>
      <?php if ($pct >= 85): ?>
        <?= aviso('advertencia', 'La Fast Recovery Area está por encima del 85%. Un respaldo puede ' .
                  'fallar por falta de espacio (ORA-19809).') ?>
      <?php endif; ?>
    <?php endif; ?>

    <p class="nota"><?= count($contexto['tablespaces']) ?> tablespaces y
       <?= count($contexto['datafiles']) ?> datafiles disponibles para incluir en una estrategia.</p>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-titulo">
    <h2>Bases registradas <?php if ($bases): ?><span class="contador"><?= count($bases) ?></span><?php endif; ?></h2>
  </div>

  <?php if (!$bases): ?>
    <div class="vacio-guia">
      <div class="vacio-icono" aria-hidden="true">
        <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7" ry="2.5"/><path d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13"/><path d="M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/></svg>
      </div>
      <h3>Todavía no hay bases registradas</h3>
      <p>Registrá tu primera instancia Oracle para poder crearle estrategias de respaldo.</p>
      <?php if (esAdmin()): ?>
        <a class="boton primario" href="#registrar">Registrar mi primera base</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table>
        <thead>
          <tr>
            <th>Base</th>
            <th>Ambiente</th>
            <th>Conexión</th>
            <th>Archivado <?= ayuda('Columna Archivado',
                'Se llena al presionar <b>Verificar</b>. «DESCONOCIDO» significa que todavía no se ha '
              . 'podido leer de Oracle.') ?></th>
            <th>Conexión verificada</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bases as $b): ?>
          <?php $recien = $verificadaId !== null && (int) $b['id'] === $verificadaId; ?>
          <tr class="<?= $recien ? 'fila-verificada' : '' ?>">
            <td><strong><?= e($b['nombre']) ?></strong>
                <?php if ($recien): ?><span class="insignia ok">✓ Verificada ahora</span><?php endif; ?>
                <?php if ($b['descripcion']): ?><br><span class="muted"><?= e($b['descripcion']) ?></span><?php endif; ?></td>
            <td><span class="insignia <?= $b['ambiente'] === 'produccion' ? 'warn' : 'neutra' ?>"><?= e($b['ambiente']) ?></span></td>
            <td class="mono">
              <?= $b['tns_alias']
                    ? e($b['tns_alias'])
                    : e($b['host'] . ':' . $b['puerto'] . '/' . $b['service_name']) ?><br>
              <span class="muted"><?= e($b['usuario']) ?> AS <?= (int) $b['conectar_as_sysdba'] === 1 ? 'SYSDBA' : 'SYSBACKUP' ?></span>
            </td>
            <td><?= insigniaArchivado($b['modo_archivado']) ?></td>
            <td>
              <?php if ($b['ultimo_chequeo']): ?>
                <span class="estado-conexion ok">● Conexión correcta</span><br>
                <span class="mono muted"><?= formatoFecha($b['ultimo_chequeo']) ?></span>
              <?php else: ?>
                <span class="estado-conexion pendiente">● Sin verificar</span>
              <?php endif; ?>
            </td>
            <td class="celda-acciones">
              <?php if (esAdmin()): ?>
                <form method="post">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="accion" value="verificar">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="boton" type="submit">Verificar</button>
                </form>
                <form method="post"
                      onsubmit="return confirm('¿Dar de baja esta base? Sus estrategias dejarán de ejecutarse. El historial se conserva.')">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="boton peligro" type="submit">Dar de baja</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (esAdmin()): ?>
<div class="panel" id="registrar">
  <div class="panel-titulo">
    <h2>Registrar una base</h2>
    <span class="nota">Los campos con <span class="req">*</span> son obligatorios.</span>
  </div>

  <form method="post" id="formBase">
    <?= csrfCampo() ?>
    <input type="hidden" name="accion" value="registrar">

    <!-- 1. Identificación -->
    <section class="form-seccion">
      <h3><span class="seccion-num">1</span> Identificación</h3>
      <div class="rejilla c2">
        <div class="campo">
          <label for="nombre">Nombre <span class="req">*</span>
            <?= ayuda('Nombre', 'Un nombre corto para reconocer la base dentro de BackupGuard. '
                    . 'Por ejemplo <span class="mono">XE</span> o <span class="mono">Ventas pruebas</span>.') ?></label>
          <input type="text" id="nombre" name="nombre" required placeholder="XE">
        </div>
        <div class="campo">
          <label for="ambiente">Ambiente
            <?= ayuda('Ambiente', 'Indica para qué se usa la base. Sirve para distinguirlas y para advertir '
                    . 'cuando se trabaja sobre producción.') ?></label>
          <select id="ambiente" name="ambiente">
            <option value="pruebas">Pruebas</option>
            <option value="desarrollo">Desarrollo</option>
            <option value="produccion">Producción</option>
          </select>
        </div>
      </div>
      <div class="aviso advertencia" id="avisoProduccion" hidden>
        <span class="titulo">Cuidado con producción</span>
        <p>Los scripts RMAN deben probarse primero en desarrollo o pruebas. Este proyecto no debe ejecutar
           operaciones sobre bases de producción (enunciado, sección 14).</p>
      </div>
      <div class="campo">
        <label for="descripcion">Descripción</label>
        <input type="text" id="descripcion" name="descripcion" placeholder="Oracle XE 21c local (CDB completo)">
      </div>
    </section>

    <!-- 2. Conexión -->
    <section class="form-seccion">
      <h3><span class="seccion-num">2</span> Conexión
        <?= ayuda('¿Cómo se conecta?', 'Elegí una de las dos formas. Lo más simple es <b>host, puerto y '
                . 'servicio</b>. El alias TNS solo sirve si ya tenés un archivo <span class="mono">tnsnames.ora</span> '
                . 'configurado.') ?></h3>

      <div class="segmentado" role="radiogroup" aria-label="Forma de conexión">
        <label><input type="radio" name="modo_conexion" value="directa" checked> Host, puerto y servicio</label>
        <label><input type="radio" name="modo_conexion" value="tns"> Alias TNS</label>
      </div>

      <div class="rejilla c3" data-grupo="directa">
        <div class="campo">
          <label for="host">Host <span class="req">*</span>
            <?= ayuda('Host', 'La máquina donde corre Oracle. Si está en esta misma computadora, '
                    . 'es <span class="mono">localhost</span>.') ?></label>
          <input type="text" id="host" name="host" value="localhost">
        </div>
        <div class="campo">
          <label for="puerto">Puerto
            <?= ayuda('Puerto', 'El puerto del listener de Oracle. Casi siempre es <span class="mono">1521</span>.') ?></label>
          <input type="number" id="puerto" name="puerto" value="1521">
        </div>
        <div class="campo">
          <label for="service_name">Service name <span class="req">*</span>
            <?= ayuda('Service name', 'El nombre del servicio de la base. Para respaldar con RMAN usá el '
                    . '<b>contenedor completo (CDB)</b>: en Oracle XE es <span class="mono">XE</span>, '
                    . 'no <span class="mono">XEPDB1</span>, que es solo una base enchufable dentro de él.') ?></label>
          <input type="text" id="service_name" name="service_name" placeholder="XE">
        </div>
      </div>

      <div class="campo" data-grupo="tns" hidden>
        <label for="tns_alias">Alias TNS <span class="req">*</span>
          <?= ayuda('Alias TNS', 'Un nombre definido en <span class="mono">tnsnames.ora</span> que ya incluye '
                  . 'host, puerto y servicio. El cliente de Oracle lo traduce solo.') ?></label>
        <input type="text" id="tns_alias" name="tns_alias" placeholder="XE">
      </div>
    </section>

    <!-- 3. Credenciales -->
    <section class="form-seccion">
      <h3><span class="seccion-num">3</span> Credenciales</h3>
      <div class="rejilla c2">
        <div class="campo">
          <label for="usuario">Usuario <span class="req">*</span>
            <?= ayuda('Usuario', 'El usuario de Oracle con el que RMAN se conecta. Lo recomendado es un usuario '
                    . 'dedicado a respaldos, como <span class="mono">c##bgbackup</span>, en lugar de '
                    . '<span class="mono">SYS</span>.') ?></label>
          <input type="text" id="usuario" name="usuario" required>
        </div>
        <div class="campo">
          <label for="password">Contraseña <span class="req">*</span>
            <?= ayuda('Contraseña', 'Se guarda <b>cifrada</b> con la clave definida en '
                    . '<span class="mono">includes/config.php</span>. Nunca queda en texto plano.') ?></label>
          <input type="password" id="password" name="password" required>
        </div>
      </div>

      <div class="campo">
        <label>Privilegio de conexión
          <?= porque('¿Por qué SYSBACKUP?',
              'Es el principio de <b>mínimo privilegio</b>: SYSBACKUP permite hacer y verificar respaldos, '
            . 'pero no administrar toda la base. Si la credencial se filtrara, el daño posible es mucho menor '
            . 'que con SYSDBA. Es un control preventivo en sí mismo.') ?></label>
        <div class="opciones-tarjeta">
          <label class="opcion">
            <input type="radio" name="privilegio" value="sysbackup" checked>
            <span>
              <strong>SYSBACKUP <em class="etiqueta-rec">Recomendado</em></strong>
              <small>Solo lo necesario para respaldar. Úsalo con <span class="mono">c##bgbackup</span>.</small>
            </span>
          </label>
          <label class="opcion">
            <input type="radio" name="privilegio" value="sysdba">
            <span>
              <strong>SYSDBA</strong>
              <small>Control total de la base. Solo si te conectás como <span class="mono">SYS</span>.</small>
            </span>
          </label>
        </div>
      </div>
    </section>

    <div class="form-acciones">
      <button type="submit" class="boton primario">Registrar base</button>
      <span class="nota">Después de registrarla, presioná <b>Verificar</b> en la tabla para probar la conexión.</span>
    </div>
  </form>
</div>

<script>
(function () {
  // Aviso al elegir producción
  var amb = document.getElementById('ambiente');
  var avisoProd = document.getElementById('avisoProduccion');
  amb.addEventListener('change', function () { avisoProd.hidden = amb.value !== 'produccion'; });

  // Mostrar solo los campos de la forma de conexión elegida
  var form = document.getElementById('formBase');
  function actualizarConexion() {
    var modo = form.querySelector('input[name="modo_conexion"]:checked').value;
    form.querySelectorAll('[data-grupo]').forEach(function (g) {
      var activo = g.getAttribute('data-grupo') === modo;
      g.hidden = !activo;
      g.querySelectorAll('input').forEach(function (i) { i.disabled = !activo; });
    });
  }
  form.querySelectorAll('input[name="modo_conexion"]').forEach(function (r) {
    r.addEventListener('change', actualizarConexion);
  });
  actualizarConexion();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
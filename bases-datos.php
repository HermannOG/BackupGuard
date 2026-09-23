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
                'conectar_as_sysdba' => isset($_POST['sysdba']) ? 1 : 0,
            ]);

            bitacora('registrar_base', 'bases_datos', $id, $nombre);
            $mensaje = 'Base registrada. Verificá la conexión para detectar su modo de archivado.';

        } elseif ($accion === 'verificar') {
            requiereAdmin();
            $id = (int) ($_POST['id'] ?? 0);
            $contexto = refrescarModoArchivado($id);
            $mensaje = 'Conexión correcta. Modo de archivado detectado: ' . $contexto['modo_archivado'] . '.';

        } elseif ($accion === 'eliminar') {
            requiereAdmin();
            $id = (int) ($_POST['id'] ?? 0);
            $repo->eliminarBase($id);
            bitacora('baja_base', 'bases_datos', $id);
            $mensaje = 'La base se dio de baja. Su historial de respaldos se conserva.';
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
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
    <p class="sub">Instancias Oracle sobre las que se ejecutan las estrategias.</p>
  </div>
</div>

<?= $error   ? aviso('error', $error)    : '' ?>
<?= $mensaje ? aviso('exito', $mensaje)  : '' ?>

<?php if (!oracleDisponible()): ?>
  <?= aviso('advertencia',
      'La extensión oci8 de PHP no está habilitada, así que no se puede leer el modo de archivado ' .
      'automáticamente ni ejecutar RMAN desde aquí. Podés trabajar en modo simulación mientras tanto.') ?>
<?php endif; ?>

<?php if ($contexto): ?>
  <div class="panel">
    <h2>Contexto leído de <?= e($contexto['nombre_bd'] ?? 'la base') ?></h2>
    <p>
      Modo: <?= insigniaArchivado($contexto['modo_archivado']) ?> ·
      Estado: <span class="mono"><?= e($contexto['open_mode'] ?? '?') ?></span> ·
      DBID: <span class="mono"><?= e($contexto['dbid'] ?? '?') ?></span>
    </p>

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
         <?= formatoBytes((int) $contexto['fra']['SPACE_LIMIT']) ?>).</p>
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
  <h2>Bases registradas</h2>
  <?php if (!$bases): ?>
    <div class="vacio"><p>Todavía no hay ninguna base registrada. Registrá una abajo para empezar.</p></div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table>
        <thead>
          <tr><th>Base</th><th>Ambiente</th><th>Conexión</th><th>Archivado</th>
              <th>Último chequeo</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($bases as $b): ?>
          <tr>
            <td><strong><?= e($b['nombre']) ?></strong>
                <?php if ($b['descripcion']): ?><br><span class="muted"><?= e($b['descripcion']) ?></span><?php endif; ?></td>
            <td><span class="insignia <?= $b['ambiente'] === 'produccion' ? 'warn' : 'neutra' ?>"><?= e($b['ambiente']) ?></span></td>
            <td class="mono">
              <?= $b['tns_alias']
                    ? e($b['tns_alias'])
                    : e($b['host'] . ':' . $b['puerto'] . '/' . $b['service_name']) ?><br>
              <span class="muted"><?= e($b['usuario']) ?><?= (int) $b['conectar_as_sysdba'] === 1 ? ' AS SYSDBA' : '' ?></span>
            </td>
            <td><?= insigniaArchivado($b['modo_archivado']) ?></td>
            <td class="mono"><?= formatoFecha($b['ultimo_chequeo']) ?></td>
            <td>
              <?php if (esAdmin()): ?>
                <form method="post" style="display:inline">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="accion" value="verificar">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="boton" type="submit">Verificar</button>
                </form>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('¿Dar de baja esta base? Sus estrategias dejarán de ejecutarse.')">
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
<div class="panel">
  <h2>Registrar una base</h2>
  <p class="nota">Usá un usuario con privilegio SYSDBA o SYSBACKUP. La contraseña se guarda cifrada
     con la clave de <span class="mono">includes/config.php</span>.</p>

  <form method="post">
    <?= csrfCampo() ?>
    <input type="hidden" name="accion" value="registrar">

    <div class="rejilla c2">
      <div class="campo">
        <label for="nombre">Nombre</label>
        <input type="text" id="nombre" name="nombre" required placeholder="ORCL de pruebas">
      </div>
      <div class="campo">
        <label for="ambiente">Ambiente</label>
        <select id="ambiente" name="ambiente">
          <option value="pruebas">Pruebas</option>
          <option value="desarrollo">Desarrollo</option>
          <option value="produccion">Producción</option>
        </select>
      </div>
    </div>

    <div class="campo">
      <label for="descripcion">Descripción</label>
      <input type="text" id="descripcion" name="descripcion" placeholder="Para qué se usa esta base">
    </div>

    <div class="campo">
      <label for="tns_alias">Alias TNS (si lo tenés configurado)</label>
      <input type="text" id="tns_alias" name="tns_alias" placeholder="XEPDB1">
      <div class="ayuda">Con alias TNS no hace falta host ni puerto: los resuelve el cliente de Oracle.</div>
    </div>

    <div class="rejilla c3">
      <div class="campo">
        <label for="host">Host</label>
        <input type="text" id="host" name="host" placeholder="localhost">
      </div>
      <div class="campo">
        <label for="puerto">Puerto</label>
        <input type="number" id="puerto" name="puerto" value="1521">
      </div>
      <div class="campo">
        <label for="service_name">Service name</label>
        <input type="text" id="service_name" name="service_name" placeholder="XEPDB1">
      </div>
    </div>

    <div class="rejilla c2">
      <div class="campo">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" required placeholder="sys">
      </div>
      <div class="campo">
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>
      </div>
    </div>

    <div class="check">
      <input type="checkbox" id="sysdba" name="sysdba" checked>
      <label for="sysdba">Conectar AS SYSDBA</label>
    </div>

    <button type="submit" class="boton primario">Registrar base</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

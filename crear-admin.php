<?php
/**
 * Crea el PRIMER usuario administrador.
 * Deja de funcionar apenas exista un admin: no hace falta borrarlo a mano,
 * pero igual conviene eliminarlo del servidor después de usarlo.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui.php';

$yaExiste = (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn() > 0;
$mensaje = null;
$error = null;

if (!$yaExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $password = $_POST['password'] ?? '';

    if (strlen($usuario) < 3 || strlen($password) < 8) {
        $error = 'El usuario necesita al menos 3 caracteres y la contraseña al menos 8.';
    } else {
        $stmt = db()->prepare("
            INSERT INTO usuarios (nombre_usuario, password_hash, nombre_completo, rol)
            VALUES (:u, :p, :n, 'admin')
        ");
        $stmt->execute([
            'u' => $usuario,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'n' => $nombre ?: null,
        ]);
        $mensaje = 'Administrador creado. Ya podés entrar y borrar este archivo del servidor.';
        $yaExiste = true;
    }
}

$tituloPagina = 'Crear administrador';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="login-caja">
  <h1>Primer administrador</h1>

  <?= $error ? aviso('error', $error) : '' ?>
  <?= $mensaje ? aviso('exito', $mensaje) : '' ?>

  <?php if ($yaExiste): ?>
    <div class="panel">
      <p>Ya existe al menos un administrador, así que esta pantalla está cerrada.</p>
      <p class="nota">Borrá <span class="mono">crear-admin.php</span> del servidor y creá el resto
         de usuarios desde el panel.</p>
      <a class="boton primario" href="login.php">Ir a entrar</a>
    </div>
  <?php else: ?>
    <form method="post" class="panel">
      <div class="campo">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" required autofocus>
      </div>
      <div class="campo">
        <label for="nombre">Nombre completo</label>
        <input type="text" id="nombre" name="nombre">
      </div>
      <div class="campo">
        <label for="password">Contraseña (mínimo 8 caracteres)</label>
        <input type="password" id="password" name="password" required minlength="8">
      </div>
      <button type="submit" class="boton primario">Crear administrador</button>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

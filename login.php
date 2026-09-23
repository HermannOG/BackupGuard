<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';

if (usuarioActual()) { header('Location: index.php'); exit; }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($usuario === '' || $password === '') {
        $error = 'Escribí usuario y contraseña.';
    } else {
        $stmt = db()->prepare("SELECT * FROM usuarios WHERE nombre_usuario = :u AND activo = 1");
        $stmt->execute(['u' => $usuario]);
        $fila = $stmt->fetch();

        if ($fila && password_verify($password, $fila['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario'] = [
                'id'             => (int) $fila['id'],
                'nombre_usuario' => $fila['nombre_usuario'],
                'nombre_completo'=> $fila['nombre_completo'],
                'rol'            => $fila['rol'],
            ];
            bitacora('login', 'usuarios', (int) $fila['id']);
            $destino = $_GET['redirect'] ?? 'index.php';
            if (str_contains($destino, 'login.php')) { $destino = 'index.php'; }
            header('Location: ' . $destino);
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$tituloPagina = 'Entrar';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="login-caja">
  <h1>Entrar a BackupGuard</h1>
  <p class="muted">Gestión de estrategias de respaldo Oracle con RMAN.</p>

  <?= $error ? aviso('error', $error) : '' ?>

  <form method="post" class="panel">
    <div class="campo">
      <label for="usuario">Usuario</label>
      <input type="text" id="usuario" name="usuario" autofocus required
             value="<?= e($_POST['usuario'] ?? '') ?>">
    </div>
    <div class="campo">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="boton primario">Entrar</button>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

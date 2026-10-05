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

$modoSim = (bool) (config()['modo_simulacion'] ?? true);

$tituloPagina = 'Entrar';
require_once __DIR__ . '/includes/header.php';
?>

<div class="acceso">

  <!-- Panel izquierdo: presentación breve -->
  <section class="acceso-info">
    <div class="marca acceso-marca">
      <svg class="marca-logo" viewBox="0 0 32 32" aria-hidden="true">
        <path class="escudo" d="M16 2.5 27 6.5v8.2c0 7-4.6 12.3-11 14.8C9.6 27 5 21.7 5 14.7V6.5Z"/>
        <ellipse class="bd" cx="16" cy="11.5" rx="5.5" ry="2"/>
        <path class="bd" d="M10.5 11.5v7.5c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2v-7.5"/>
        <path class="bd" d="M10.5 15.3c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2"/>
      </svg>
      <span class="marca-texto">Backup<em>Guard</em></span>
    </div>

    <div class="acceso-titulo">
      <h1>Respaldos Oracle <em>bajo control</em>.</h1>
      <p class="acceso-lead">Planificados, automatizados y verificables con RMAN.</p>

      <div class="acceso-codigo" aria-hidden="true">
        <div class="codigo-barra"><span></span><span></span><span></span><em>EST001.rma</em></div>
        <pre><span class="c-k">RUN</span> {
  <span class="c-k">BACKUP</span> INCREMENTAL LEVEL 0 <span class="c-o">DATABASE</span>;
  <span class="c-k">BACKUP</span> <span class="c-o">ARCHIVELOG</span> ALL;
}</pre>
        <div class="codigo-estado"><span class="punto-ok"></span>Respaldo verificado · evidencia registrada</div>
      </div>
    </div>

    <div class="acceso-pie">
      <div class="acceso-riesgos">
        <span>Disponibilidad</span>
        <span>Integridad</span>
      </div>
      <span>EIF402 · UNA · II ciclo <?= date('Y') ?></span>
    </div>
  </section>

  <!-- Panel derecho: formulario -->
  <section class="acceso-form">
    <div class="acceso-caja">
      <h2>Iniciar sesión</h2>
      <p class="muted">Ingresá con tu usuario de BackupGuard.</p>

      <?= $error ? aviso('error', $error) : '' ?>

      <form method="post" novalidate>
        <div class="campo">
          <label for="usuario">Usuario</label>
          <div class="campo-icono">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <input type="text" id="usuario" name="usuario" autofocus required
                   autocomplete="username" placeholder="nombre de usuario"
                   value="<?= e($_POST['usuario'] ?? '') ?>">
          </div>
        </div>

        <div class="campo">
          <label for="password">Contraseña</label>
          <div class="campo-icono">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" placeholder="••••••••">
            <button type="button" class="ver-clave" id="verClave" aria-label="Mostrar contraseña">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="boton primario boton-ancho">Entrar</button>
      </form>

      <div class="acceso-notas">
        <span class="acceso-local">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
          Acceso solo desde esta computadora
        </span>
        <span class="pie-modo <?= $modoSim ? 'sim' : 'real' ?>"><?= $modoSim ? 'Modo simulación' : 'Modo real' ?></span>
      </div>
    </div>
  </section>

</div>

<script>
document.getElementById('verClave').addEventListener('click', function () {
  var campo = document.getElementById('password');
  var mostrar = campo.type === 'password';
  campo.type = mostrar ? 'text' : 'password';
  this.classList.toggle('activo', mostrar);
  this.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
</script>
</body>
</html>

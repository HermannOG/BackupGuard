<?php
$u = $_SESSION['usuario'] ?? null;
$actual = basename($_SERVER['PHP_SELF']);
$enlaces = [
    'index.php'       => 'Tablero',
    'estrategias.php' => 'Estrategias',
    'catalogo.php'    => 'Catálogo',
    'bases-datos.php' => 'Bases de datos',
    'historial.php'   => 'Historial',
    'alertas.php'     => 'Alertas',
];
$inicial = $u ? strtoupper(substr($u['nombre_usuario'], 0, 1)) : '';
?>
<nav class="nav">
  <div class="container nav-inner">
    <a class="marca" href="index.php">
      <svg class="marca-logo" viewBox="0 0 32 32" aria-hidden="true">
        <path class="escudo" d="M16 2.5 27 6.5v8.2c0 7-4.6 12.3-11 14.8C9.6 27 5 21.7 5 14.7V6.5Z"/>
        <ellipse class="bd" cx="16" cy="11.5" rx="5.5" ry="2"/>
        <path class="bd" d="M10.5 11.5v7.5c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2v-7.5"/>
        <path class="bd" d="M10.5 15.3c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2"/>
      </svg>
      <span class="marca-texto">Backup<em>Guard</em></span>
    </a>
    <?php if ($u): ?>
      <div class="nav-links">
        <?php foreach ($enlaces as $href => $texto): ?>
          <a href="<?= $href ?>" class="<?= $actual === $href ? 'activo' : '' ?>"><?= $texto ?></a>
        <?php endforeach; ?>
      </div>
      <div class="nav-cuenta">
        <span class="nav-avatar" aria-hidden="true"><?= e($inicial) ?></span>
        <div class="nav-usuario">
          <span class="nombre"><?= e($u['nombre_usuario']) ?></span>
          <span class="rol"><?= e($u['rol']) ?></span>
        </div>
        <a class="nav-salir" href="logout.php" title="Cerrar sesión">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/></svg>
          Salir
        </a>
      </div>
    <?php endif; ?>
  </div>
</nav>
<main><div class="container">
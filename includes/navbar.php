<?php
$u = $_SESSION['usuario'] ?? null;
$actual = basename($_SERVER['PHP_SELF']);
$enlaces = [
    'index.php'       => 'Tablero',
    'estrategias.php' => 'Estrategias',
    'bases-datos.php' => 'Bases de datos',
    'historial.php'   => 'Historial',
    'alertas.php'     => 'Alertas',
];
?>
<nav class="nav">
  <div class="container nav-inner">
    <a class="marca" href="index.php">
      <span class="marca-cinta" aria-hidden="true"></span>
      Backup<em>Guard</em>
    </a>
    <?php if ($u): ?>
      <div class="nav-links">
        <?php foreach ($enlaces as $href => $texto): ?>
          <a href="<?= $href ?>" class="<?= $actual === $href ? 'activo' : '' ?>"><?= $texto ?></a>
        <?php endforeach; ?>
        <span class="nav-usuario"><?= e($u['nombre_usuario']) ?> · <?= e($u['rol']) ?></span>
        <a href="logout.php">Salir</a>
      </div>
    <?php endif; ?>
  </div>
</nav>
<main><div class="container">

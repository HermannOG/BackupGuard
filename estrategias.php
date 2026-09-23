<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/Programacion.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$estrategias = $repo->listar();

$tituloPagina = 'Estrategias';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Estrategias de respaldo</h1>
    <p class="sub">Cada estrategia responde qué se respalda, cómo y cuándo.</p>
  </div>
  <?php if (puedeEditar()): ?>
    <div class="acciones"><a class="boton primario" href="estrategia-form.php">Nueva estrategia</a></div>
  <?php endif; ?>
</div>

<div class="panel">
<?php if (!$estrategias): ?>
  <div class="vacio">
    <p>Todavía no hay estrategias definidas.</p>
    <?php if (puedeEditar()): ?>
      <a class="boton primario" href="estrategia-form.php">Crear la primera</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="tabla-envoltura">
    <table>
      <thead>
        <tr><th>Estrategia</th><th>Base</th><th>Qué</th><th>Cómo</th><th>Cuándo</th>
            <th>Script</th><th>Última ejecución</th></tr>
      </thead>
      <tbody>
      <?php foreach ($estrategias as $e): ?>
        <tr>
          <td>
            <a href="estrategia-detalle.php?id=<?= (int) $e['id'] ?>"><strong><?= e($e['nombre']) ?></strong></a><br>
            <?= insigniaPrioridad($e['prioridad']) ?> <?= insigniaEstado($e['estado']) ?>
          </td>
          <td><?= e($e['base_nombre']) ?><br><?= insigniaArchivado($e['modo_archivado']) ?></td>
          <td><?= e(str_replace('_', ' ', $e['alcance'])) ?>
              <?= (int) $e['incluir_archivelogs'] ? '<br><span class="muted">+ archivelogs</span>' : '' ?></td>
          <td><?= e(str_replace('_', ' ', $e['tipo_respaldo'])) ?>
              <?= $e['modalidad'] ? '<br><span class="muted">' . e($e['modalidad']) . '</span>' : '' ?></td>
          <td class="mono" style="white-space:normal"><?= e(Programacion::describir($e)) ?></td>
          <td>
            <?php if ((int) $e['aprobado'] === 1): ?>
              <span class="insignia ok">Aprobado</span>
            <?php elseif (!empty($e['script_rman'])): ?>
              <span class="insignia warn">Sin aprobar</span>
            <?php else: ?>
              <span class="insignia neutra">Sin generar</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= formatoFecha($e['ultima_ejecucion']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

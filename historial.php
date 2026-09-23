<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';

requiereLogin();

$repo = new EstrategiaRepository();
$estrategiaId = isset($_GET['estrategia']) ? (int) $_GET['estrategia'] : null;
$ejecuciones = $repo->historial($estrategiaId, 100);
$estrategias = $repo->listar();
$resumen = $repo->resumen(30);

$tituloPagina = 'Historial';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Historial de ejecuciones</h1>
    <p class="sub">Evidencia de cada respaldo: cuándo corrió, cuánto tardó y cómo terminó.</p>
  </div>
</div>

<div class="rejilla c4">
  <div class="metrica ok"><div class="valor"><?= $resumen['exitoso'] ?></div><div class="etiqueta">Exitosos (30 días)</div></div>
  <div class="metrica warn"><div class="valor"><?= $resumen['advertencia'] ?></div><div class="etiqueta">Con advertencias</div></div>
  <div class="metrica bad"><div class="valor"><?= $resumen['fallido'] ?></div><div class="etiqueta">Fallidos</div></div>
  <div class="metrica"><div class="valor"><?= $resumen['en_curso'] ?></div><div class="etiqueta">En curso</div></div>
</div>

<div class="panel" style="margin-top:1.2rem">
  <form method="get" class="campo" style="max-width:420px">
    <label for="estrategia">Filtrar por estrategia</label>
    <select id="estrategia" name="estrategia" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($estrategias as $es): ?>
        <option value="<?= (int) $es['id'] ?>" <?= $estrategiaId === (int) $es['id'] ? 'selected' : '' ?>>
          <?= e($es['nombre']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$ejecuciones): ?>
    <div class="vacio"><p>No hay ejecuciones registradas todavía.</p></div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table>
        <thead>
          <tr><th>Inicio</th><th>Estrategia</th><th>Base</th><th>Tipo</th>
              <th>Duración</th><th>Origen</th><th>Resultado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($ejecuciones as $ej): ?>
          <tr>
            <td class="mono"><?= formatoFecha($ej['inicio']) ?></td>
            <td><?= e($ej['estrategia_nombre']) ?></td>
            <td><?= e($ej['base_nombre']) ?></td>
            <td class="mono"><?= e(str_replace('_', ' ', $ej['tipo_respaldo'])) ?></td>
            <td><?= formatoDuracion($ej['duracion_seg'] !== null ? (int) $ej['duracion_seg'] : null) ?></td>
            <td><?= e($ej['origen']) ?></td>
            <td><?= insigniaResultado($ej['resultado']) ?>
                <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?></td>
            <td><a href="ejecucion-detalle.php?id=<?= (int) $ej['id'] ?>">Evidencia →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Tablero: lo primero que ve el administrador. Responde tres preguntas:
 * ¿qué está mal ahora?, ¿qué se respaldó últimamente?, ¿qué viene después?
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';
require_once __DIR__ . '/includes/Alertas.php';

requiereLogin();

$repo = new EstrategiaRepository();
$motorAlertas = new Alertas();

// El control preventivo se re-evalúa en cada visita al tablero.
$alertas = $motorAlertas->evaluar();
$conteoAlertas = $motorAlertas->conteo();

$resumen = $repo->resumen(30);
$estrategias = $repo->listar();
$ultimas = $repo->historial(null, 8);

$activas = count(array_filter($estrategias, fn($e) => $e['estado'] === 'activa'));
$proximas = array_values(array_filter($estrategias, fn($e) => !empty($e['proxima_ejecucion'])));
usort($proximas, fn($a, $b) => strcmp($a['proxima_ejecucion'], $b['proxima_ejecucion']));
$proximas = array_slice($proximas, 0, 5);

$tituloPagina = 'Tablero';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Tablero</h1>
    <p class="sub">Estado de las estrategias de respaldo y de los riesgos detectados.</p>
  </div>
  <?php if (puedeEditar()): ?>
    <div class="acciones">
      <a class="boton primario" href="estrategia-form.php">Nueva estrategia</a>
    </div>
  <?php endif; ?>
</div>

<div class="rejilla c4">
  <div class="metrica">
    <div class="valor"><?= $activas ?><span class="muted" style="font-size:1rem">/<?= count($estrategias) ?></span></div>
    <div class="etiqueta">Estrategias activas</div>
  </div>
  <div class="metrica ok">
    <div class="valor"><?= $resumen['exitoso'] ?></div>
    <div class="etiqueta">Respaldos exitosos (30 días)</div>
  </div>
  <div class="metrica warn">
    <div class="valor"><?= $resumen['advertencia'] ?></div>
    <div class="etiqueta">Con advertencias</div>
  </div>
  <div class="metrica bad">
    <div class="valor"><?= $resumen['fallido'] ?></div>
    <div class="etiqueta">Fallidos</div>
  </div>
</div>

<?php if ($alertas): ?>
  <div class="panel" style="margin-top:1.2rem">
    <h2>Control preventivo</h2>
    <p class="nota">
      <?= $conteoAlertas['critica'] ?> críticas ·
      <?= $conteoAlertas['advertencia'] ?> advertencias ·
      <?= $conteoAlertas['recomendacion'] ?> recomendaciones
    </p>
    <?php foreach (array_slice($alertas, 0, 6) as $a): ?>
      <div class="aviso <?= e($a['severidad']) ?>">
        <span class="titulo"><?= insigniaSeveridad($a['severidad']) ?> <?= e($a['codigo']) ?></span>
        <p><?= e($a['mensaje']) ?></p>
      </div>
    <?php endforeach; ?>
    <?php if (count($alertas) > 6): ?>
      <a href="alertas.php">Ver las <?= count($alertas) ?> alertas →</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="panel" style="margin-top:1.2rem">
    <h2>Control preventivo</h2>
    <?= aviso('exito', 'No hay condiciones de riesgo detectadas en este momento.') ?>
  </div>
<?php endif; ?>

<div class="rejilla c2" style="margin-top:1.2rem">

  <div class="panel">
    <h2>Próximas ejecuciones</h2>
    <?php if (!$proximas): ?>
      <div class="vacio">
        <p>Ninguna estrategia tiene una ejecución programada.</p>
        <?php if (puedeEditar()): ?><a class="boton" href="estrategia-form.php">Crear la primera</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table>
          <thead><tr><th>Estrategia</th><th>Base</th><th>Cuándo</th></tr></thead>
          <tbody>
          <?php foreach ($proximas as $p): ?>
            <tr>
              <td><a href="estrategia-detalle.php?id=<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a><br>
                  <?= insigniaPrioridad($p['prioridad']) ?></td>
              <td><?= e($p['base_nombre']) ?></td>
              <td class="mono"><?= formatoFecha($p['proxima_ejecucion']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Últimas ejecuciones</h2>
    <?php if (!$ultimas): ?>
      <div class="vacio"><p>Todavía no se ha ejecutado ningún respaldo.</p></div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table>
          <thead><tr><th>Inicio</th><th>Estrategia</th><th>Resultado</th></tr></thead>
          <tbody>
          <?php foreach ($ultimas as $ej): ?>
            <tr>
              <td class="mono"><?= formatoFecha($ej['inicio']) ?></td>
              <td><a href="ejecucion-detalle.php?id=<?= (int) $ej['id'] ?>"><?= e($ej['estrategia_nombre']) ?></a></td>
              <td><?= insigniaResultado($ej['resultado']) ?>
                  <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:.9rem"><a href="historial.php">Ver historial completo →</a></p>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

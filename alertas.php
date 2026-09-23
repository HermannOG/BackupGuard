<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/Alertas.php';

requiereLogin();

$motor = new Alertas();
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    requiereEdicion();
    $motor->marcarAtendida((int) ($_POST['id'] ?? 0), $_SESSION['usuario']['nombre_usuario']);
    bitacora('atender_alerta', 'alertas', (int) ($_POST['id'] ?? 0));
    $mensaje = 'Alerta marcada como atendida. Si la condición sigue, volverá a aparecer.';
}

$alertas = $motor->evaluar();
$conteo  = $motor->conteo();

$tituloPagina = 'Alertas';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Alertas preventivas</h1>
    <p class="sub">Condiciones detectadas que pueden comprometer la disponibilidad o la
       integridad de la información.</p>
  </div>
</div>

<?= $mensaje ? aviso('exito', $mensaje) : '' ?>

<div class="rejilla c4">
  <div class="metrica bad"><div class="valor"><?= $conteo['critica'] ?></div><div class="etiqueta">Críticas</div></div>
  <div class="metrica warn"><div class="valor"><?= $conteo['advertencia'] ?></div><div class="etiqueta">Advertencias</div></div>
  <div class="metrica ok"><div class="valor"><?= $conteo['recomendacion'] ?></div><div class="etiqueta">Recomendaciones</div></div>
  <div class="metrica"><div class="valor"><?= $conteo['informacion'] ?></div><div class="etiqueta">Informativas</div></div>
</div>

<div class="panel" style="margin-top:1.2rem">
<?php if (!$alertas): ?>
  <?= aviso('exito', 'No hay condiciones de riesgo detectadas en este momento.') ?>
<?php else: ?>
  <div class="tabla-envoltura">
    <table>
      <thead><tr><th>Severidad</th><th>Código</th><th>Mensaje</th><th>Detectada</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($alertas as $a): ?>
        <tr>
          <td><?= insigniaSeveridad($a['severidad']) ?></td>
          <td class="mono"><?= e($a['codigo']) ?></td>
          <td style="white-space:normal">
            <?= e($a['mensaje']) ?>
            <?php if ($a['estrategia_id']): ?>
              <br><a href="estrategia-detalle.php?id=<?= (int) $a['estrategia_id'] ?>">Ver estrategia →</a>
            <?php elseif ($a['base_datos_id']): ?>
              <br><a href="bases-datos.php">Ver bases de datos →</a>
            <?php endif; ?>
          </td>
          <td class="mono"><?= formatoFecha($a['detectada_en']) ?></td>
          <td>
            <?php if (puedeEditar()): ?>
              <form method="post">
                <?= csrfCampo() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="boton" type="submit">Atender</button>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>

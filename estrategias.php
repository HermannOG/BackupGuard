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

<?= guia(
    'Cada estrategia define qué se respalda, cómo y cuándo. Desde aquí ves todas y su estado.',
    [
        ['titulo' => 'Creá la estrategia',
         'texto'  => 'Con <b>Nueva estrategia</b> definís alcance, tipo de respaldo y programación.'],
        ['titulo' => 'Revisá y aprobá el script',
         'texto'  => 'Al abrirla vas a ver el script RMAN generado. Hasta que un administrador lo <b>apruebe</b>, no se ejecuta.'],
        ['titulo' => 'Activala',
         'texto'  => 'Solo las estrategias <b>activas y aprobadas</b> las corre el ejecutor automático en su horario.'],
    ],
    'Separar quién diseña la estrategia de quién la aprueba evita que un error llegue directo a la base. '
  . 'Ningún script se ejecuta sin que una persona lo haya revisado.',
    !$estrategias
) ?>

<div class="panel">
<?php if (!$estrategias): ?>
  <div class="vacio-guia">
    <div class="vacio-icono" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
    </div>
    <h3>Todavía no hay estrategias</h3>
    <p>Una estrategia responde tres preguntas: <b>qué</b> respaldar, <b>cómo</b> y <b>cuándo</b>.
       Creá la primera para empezar a proteger tu base.</p>
    <?php if (puedeEditar()): ?>
      <a class="boton primario" href="estrategia-form.php">Crear la primera estrategia</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="tabla-envoltura">
    <table>
      <thead>
        <tr><th>Estrategia</th><th>Base</th><th>Qué</th><th>Cómo</th><th>Cuándo</th>
            <th>Script <?= ayuda('Estado del script', '<b>Sin generar</b>: todavía no se construyó.<br><b>Sin aprobar</b>: '
                . 'existe, pero nadie lo revisó; no se ejecuta.<br><b>Aprobado</b>: listo para ejecutarse.') ?></th><th>Última ejecución</th></tr>
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
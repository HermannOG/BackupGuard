<?php
/**
 * Tablero: lo primero que ve el administrador. Responde tres preguntas:
 * ¿qué está mal ahora?, ¿qué se respaldó últimamente?, ¿qué viene después?
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/RmanBuilder.php';
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

$listas = count(array_filter($estrategias, fn($e) => $e['estado'] === 'activa' && (int) $e['aprobado'] === 1));
$nombreUsuario = $_SESSION['usuario']['nombre_completo'] ?: $_SESSION['usuario']['nombre_usuario'];
$primerNombre = explode(' ', trim((string) $nombreUsuario))[0];

$tituloPagina = 'Tablero';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Hola, <?= e($primerNombre) ?></h1>
    <p class="sub">Este es el estado de tus estrategias de respaldo y de los riesgos detectados.</p>
  </div>
  <?php if (puedeEditar()): ?>
    <div class="acciones">
      <a class="boton primario" href="estrategia-form.php">+ Nueva estrategia</a>
    </div>
  <?php endif; ?>
</div>

<?= guia(
    'El tablero resume todo en una pantalla: qué está mal ahora, qué se respaldó y qué viene.',
    [
        ['titulo' => 'Registrá una base',
         'texto'  => 'En <a href="bases-datos.php">Bases de datos</a>, la instancia Oracle a proteger.'],
        ['titulo' => 'Creá y aprobá una estrategia',
         'texto'  => 'En <a href="estrategias.php">Estrategias</a>: qué, cómo y cuándo; después revisás y aprobás el script.'],
        ['titulo' => 'Activala',
         'texto'  => 'El ejecutor automático la corre en su horario y deja la evidencia en el <a href="historial.php">Historial</a>.'],
        ['titulo' => 'Atendé las alertas',
         'texto'  => 'Si algo puede fallar, aparece aquí y en <a href="alertas.php">Alertas</a> antes de que sea un problema.'],
    ],
    null,
    !$estrategias
) ?>

<div class="rejilla c4">
  <div class="metrica">
    <div class="valor"><?= $activas ?><span class="valor-total">/<?= count($estrategias) ?></span></div>
    <div class="etiqueta">Estrategias activas
      <?= ayuda('Estrategias activas', 'Cuántas estrategias están activas del total. De esas, <b>' . $listas . '</b> '
              . 'además tienen el script aprobado, que es la condición para que el ejecutor las corra solas.') ?></div>
  </div>
  <div class="metrica ok">
    <div class="valor"><?= $resumen['exitoso'] ?></div>
    <div class="etiqueta">Exitosos · 30 días</div>
  </div>
  <div class="metrica warn">
    <div class="valor"><?= $resumen['advertencia'] ?></div>
    <div class="etiqueta">Con advertencias · 30 días</div>
  </div>
  <div class="metrica bad">
    <div class="valor"><?= $resumen['fallido'] ?></div>
    <div class="etiqueta">Fallidos · 30 días</div>
  </div>
</div>

<!-- Control preventivo -->
<div class="panel">
  <div class="panel-titulo">
    <h2>Control preventivo
      <?= ayuda('Control preventivo', 'Se re-evalúa cada vez que abrís el tablero. Muestra las alertas más '
              . 'importantes; el detalle completo y la opción de atenderlas están en Alertas.') ?></h2>
    <div class="conteo-avisos">
      <?php foreach (['critica' => ['bad', 'crítica', 'críticas'], 'advertencia' => ['warn', 'advertencia', 'advertencias'],
                      'recomendacion' => ['ok', 'recomendación', 'recomendaciones'],
                      'informacion' => ['info', 'informativa', 'informativas']] as $sev => [$cls, $sing, $plur]): ?>
        <?php if ($conteoAlertas[$sev] > 0): ?>
          <span class="insignia <?= $cls ?>"><?= $conteoAlertas[$sev] ?> <?= $conteoAlertas[$sev] === 1 ? $sing : $plur ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$alertas): ?>
    <div class="todo-ok">
      <span class="todo-ok-icono" aria-hidden="true">✓</span>
      <div><strong>Todo en orden</strong><span>No hay condiciones de riesgo detectadas en este momento.</span></div>
    </div>
  <?php else: ?>
    <div class="lista-alertas">
      <?php foreach (array_slice($alertas, 0, 4) as $a): ?>
        <?= tarjetaAlerta($a, true) ?>
      <?php endforeach; ?>
    </div>
    <a class="enlace-mas" href="alertas.php">
      <?= count($alertas) > 4 ? 'Ver las ' . count($alertas) . ' alertas' : 'Ir a Alertas' ?> →
    </a>
  <?php endif; ?>
</div>

<div class="rejilla c2">

  <div class="panel">
    <h2>Próximas ejecuciones
      <?= ayuda('Próximas ejecuciones', 'Las estrategias activas y aprobadas, en el orden en que el ejecutor '
              . 'automático las va a correr.') ?></h2>
    <?php if (!$proximas): ?>
      <div class="vacio-guia">
        <div class="vacio-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
        </div>
        <?php if (!$estrategias): ?>
          <h3>No hay nada programado</h3>
          <p>Creá tu primera estrategia para empezar a proteger una base.</p>
          <?php if (puedeEditar()): ?><a class="boton primario" href="estrategia-form.php">Crear estrategia</a><?php endif; ?>
        <?php else: ?>
          <h3>Ninguna ejecución programada</h3>
          <p>Tenés <?= count($estrategias) ?> estrategia(s), pero ninguna está <b>activa y aprobada</b>.
             Revisalas para que el ejecutor pueda correrlas.</p>
          <a class="boton" href="estrategias.php">Ver estrategias</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="lista-filas">
        <?php foreach ($proximas as $p): ?>
          <li>
            <a href="estrategia-detalle.php?id=<?= (int) $p['id'] ?>" class="enlace-est">
              <span class="codigo-mini"><?= e(RmanBuilder::codigo((int) $p['id'])) ?></span><?= e($p['nombre']) ?>
            </a>
            <span class="muted sub-celda">Base <?= e($p['base_nombre']) ?> · <?= e(tipoRespaldoLegible($p['tipo_respaldo'], $p['modalidad'] ?? null)) ?></span>
            <span class="fila-derecha mono"><?= formatoFecha($p['proxima_ejecucion']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Últimas ejecuciones</h2>
    <?php if (!$ultimas): ?>
      <div class="vacio-guia">
        <div class="vacio-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
        </div>
        <h3>Sin ejecuciones todavía</h3>
        <p>Cuando se ejecute un respaldo, su resultado aparecerá aquí.</p>
      </div>
    <?php else: ?>
      <ul class="lista-filas">
        <?php foreach ($ultimas as $ej): ?>
          <li class="fila-<?= e($ej['resultado']) ?>">
            <a href="ejecucion-detalle.php?id=<?= (int) $ej['id'] ?>" class="enlace-est">
              <span class="codigo-mini"><?= e(RmanBuilder::codigo((int) $ej['estrategia_id'])) ?></span><?= e($ej['estrategia_nombre']) ?>
            </a>
            <span class="muted sub-celda mono"><?= formatoFecha($ej['inicio']) ?></span>
            <span class="fila-derecha">
              <?= insigniaResultado($ej['resultado']) ?>
              <?= (int) $ej['simulado'] === 1 ? '<span class="insignia neutra">simulado</span>' : '' ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="enlace-mas" href="historial.php">Ver historial completo →</a>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Recuperación ante fallos — el control correctivo.
 *
 * Catálogo de escenarios de fallo de medios con la estrategia y las
 * instrucciones de recuperación de cada uno, y la cobertura que dan las
 * estrategias de la base elegida. Solo muestra: no ejecuta nada.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/EstrategiaRepository.php';
require_once __DIR__ . '/includes/Escenarios.php';

requiereLogin();

$repo = new EstrategiaRepository();
$bases = $repo->listarBases(true);
$baseId = isset($_GET['base']) ? (int) $_GET['base'] : ($bases ? (int) $bases[0]['id'] : 0);
$base = null;
foreach ($bases as $b) {
    if ((int) $b['id'] === $baseId) { $base = $b; }
}

$escenarios = Escenarios::todos();
$ctx = $base ? Escenarios::contexto(db(), $base) : null;

$conteo = ['cubierto' => 0, 'parcial' => 0, 'sin' => 0, 'config' => 0, 'noaplica' => 0];
foreach ($escenarios as &$es) {
    $es['cob'] = $ctx ? Escenarios::cobertura($es['id'], $ctx) : null;
    if ($es['cob']) { $conteo[$es['cob'][0]]++; }
}
unset($es);

$etqCob = [
    'cubierto' => ['ok',     'Cubierto'],
    'parcial'  => ['warn',   'Parcial'],
    'sin'      => ['bad',    'Sin cobertura'],
    'config'   => ['info',   'Configuración'],
    'noaplica' => ['neutra', 'No aplica'],
];
$etqExp = ['Crítico' => 'bad', 'Alto' => 'warn', 'Medio' => 'info', 'Bajo' => 'neutra'];
$categorias = array_values(array_unique(array_column($escenarios, 'categoria')));

$tituloPagina = 'Recuperación';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="encabezado">
  <div>
    <h1>Recuperación ante fallos</h1>
    <p class="sub">El control correctivo: qué hacer cuando un respaldo hace falta de verdad.</p>
  </div>
</div>

<?= guia(
    'Cada escenario describe un fallo de medios, la estrategia que lo cubre y las instrucciones para recuperar.',
    [
        ['titulo' => 'Elegí la base',
         'texto'  => 'BackupGuard revisa sus estrategias aprobadas y te dice para qué escenarios está preparada.'],
        ['titulo' => 'Cerrá los huecos',
         'texto'  => 'Un escenario <b>sin cobertura</b> indica qué falta: ARCHIVELOG, el control file, el SPFILE, los archived logs o un respaldo real exitoso.'],
        ['titulo' => 'Ante un fallo, identificalo',
         'texto'  => 'Con el error de Oracle (por ejemplo ORA-01157) buscá el escenario y seguí sus instrucciones en un ambiente controlado.'],
    ],
    'Los respaldos son un control <b>preventivo</b>; recuperar con ellos es el control <b>correctivo</b>. Una estrategia '
  . 'solo cumple su propósito si, para cada fallo posible, se sabe qué respaldo usar y qué pasos seguir.',
    false
) ?>

<div class="aviso advertencia">
  <span class="titulo">BackupGuard no ejecuta estas instrucciones</span>
  <p>Restaurar y recuperar detienen o sobrescriben la base. Solo deben correrse en un ambiente controlado, después de
     identificar con certeza el fallo (enunciado, sección 14).</p>
</div>

<!-- ===================== COBERTURA DE LA BASE ===================== -->
<div class="panel">
  <div class="panel-titulo">
    <h2>Cobertura de la base
      <?= ayuda('Cobertura', 'Se calcula con las estrategias <b>aprobadas</b> de la base y su modo de archivado. '
              . '«Cubierto» exige además al menos una ejecución real exitosa, porque un respaldo que nunca corrió no sirve para recuperar.') ?></h2>
    <?php if ($bases): ?>
      <form method="get" class="filtro">
        <label for="base">Base</label>
        <select id="base" name="base" onchange="this.form.submit()">
          <?php foreach ($bases as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $baseId ? 'selected' : '' ?>>
              <?= e($b['nombre']) ?> (<?= e($b['modo_archivado']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$base): ?>
    <div class="vacio-guia">
      <h3>No hay bases registradas</h3>
      <p>Registrá una base para ver para qué fallos están preparadas sus estrategias.</p>
      <a class="boton primario" href="bases-datos.php">Registrar una base</a>
    </div>
  <?php else: ?>
    <div class="rejilla c4 cobertura-resumen">
      <div class="metrica ok"><div class="valor"><?= $conteo['cubierto'] ?></div><div class="etiqueta">Cubiertos</div></div>
      <div class="metrica warn"><div class="valor"><?= $conteo['parcial'] ?></div><div class="etiqueta">Parciales</div></div>
      <div class="metrica bad"><div class="valor"><?= $conteo['sin'] ?></div><div class="etiqueta">Sin cobertura</div></div>
      <div class="metrica info"><div class="valor"><?= $conteo['config'] + $conteo['noaplica'] ?></div><div class="etiqueta">Configuración o no aplica</div></div>
    </div>

    <div class="cobertura-hechos">
      <?php
        $hechos = [
            [$ctx['archivelog'], 'ARCHIVELOG'],
            [$ctx['completa'], 'Respaldo de la base completa'],
            [$ctx['controlfile'], 'Control file'],
            [$ctx['spfile'], 'SPFILE'],
            [$ctx['archivelogs'], 'Archived redo logs'],
            [$ctx['verifica'], 'Verificación (RESTORE ... VALIDATE)'],
            [(bool) $ctx['ultimo_exito'], $ctx['ultimo_exito'] ? 'Último respaldo real exitoso: ' . formatoFecha($ctx['ultimo_exito']) : 'Sin respaldo real exitoso'],
        ];
      ?>
      <?php foreach ($hechos as [$ok, $txt]): ?>
        <span class="hecho <?= $ok ? 'si' : 'no' ?>"><?= $ok ? '✓' : '✕' ?> <?= e($txt) ?></span>
      <?php endforeach; ?>
    </div>
    <p class="nota"><?= (int) $ctx['estrategias'] ?> estrategia(s) aprobada(s) para <?= e($base['nombre']) ?>.
      <a href="estrategias.php">Ver estrategias →</a></p>
  <?php endif; ?>
</div>

<!-- ===================== TIPOS DE RECUPERACIÓN ===================== -->
<details class="panel desplegable">
  <summary>
    <h2>Restaurar y recuperar: los tipos de recuperación
      <?= ayuda('Tipos de recuperación', 'La restauración copia los archivos desde el respaldo; la recuperación les aplica los cambios '
              . 'registrados en los logs. Según hasta dónde se aplican, la recuperación es completa o incompleta.') ?></h2>
    <span class="desplegable-flecha" aria-hidden="true"></span>
  </summary>
  <div class="tipos-recuperacion">
    <?php foreach (Escenarios::TIPOS_RECUPERACION as [$nombre, $cmd, $texto]): ?>
      <div class="tipo-rec">
        <strong><?= e($nombre) ?></strong>
        <span class="mono"><?= e($cmd) ?></span>
        <p><?= e($texto) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="aviso informacion">
    <span class="titulo">Cómo identificar qué falló</span>
    <p>Revisá el alert log de Oracle y consultá <span class="mono">V$RECOVER_FILE</span> (datafiles que necesitan recuperación),
       <span class="mono">V$LOG</span> y <span class="mono">V$LOGFILE</span> (redo logs) y
       <span class="mono">V$DATABASE_BLOCK_CORRUPTION</span> (bloques dañados). En RMAN, <span class="mono">REPORT SCHEMA</span>
       lista los datafiles con su número.</p>
  </div>
</details>

<!-- ===================== ESCENARIOS ===================== -->
<div class="panel">
  <div class="panel-titulo">
    <h2>Escenarios de fallo <span class="contador"><?= count($escenarios) ?></span></h2>
    <div class="filtros-esc" role="group" aria-label="Filtrar por tipo">
      <button type="button" class="chip-filtro activo" data-cat="">Todos</button>
      <?php foreach ($categorias as $cat): ?>
        <button type="button" class="chip-filtro" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tabla-envoltura">
    <table class="tabla-escenarios">
      <thead>
        <tr><th>ID</th><th>Escenario</th><th>Exposición</th><th>Pérdida de datos</th><th>Recuperación</th>
            <?php if ($ctx): ?><th>Cobertura</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($escenarios as $es): ?>
        <tr data-cat="<?= e($es['categoria']) ?>">
          <td><a class="codigo-mini" href="#<?= e($es['id']) ?>"><?= e($es['id']) ?></a></td>
          <td><a href="#<?= e($es['id']) ?>" class="enlace-esc"><?= e($es['nombre']) ?></a></td>
          <td><span class="insignia <?= $etqExp[$es['exposicion']] ?>"><?= e($es['exposicion']) ?></span></td>
          <td class="celda-chica"><?= e($es['perdida']) ?></td>
          <td class="celda-chica"><?= e($es['recuperacion']) ?></td>
          <?php if ($ctx): [$cls, $txt] = $etqCob[$es['cob'][0]]; ?>
            <td><span class="insignia <?= $cls ?>"><?= e($txt) ?></span></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="lista-escenarios">
<?php foreach ($escenarios as $es): ?>
  <details class="panel escenario" id="<?= e($es['id']) ?>" data-cat="<?= e($es['categoria']) ?>">
    <summary>
      <span class="codigo-est"><?= e($es['id']) ?></span>
      <div class="esc-titulo">
        <h3><?= e($es['nombre']) ?></h3>
        <p><?= e($es['descripcion']) ?></p>
        <div class="insignias-fila">
          <span class="insignia <?= $etqExp[$es['exposicion']] ?>">Exposición: <?= e($es['exposicion']) ?></span>
          <?php foreach ($es['riesgos'] as $r): ?><span class="riesgo"><?= e($r) ?></span><?php endforeach; ?>
          <?php if ($es['cob']): [$cls, $txt] = $etqCob[$es['cob'][0]]; ?>
            <span class="insignia <?= $cls ?>"><?= e($txt) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <span class="desplegable-flecha" aria-hidden="true"></span>
    </summary>

    <div class="esc-cuerpo">
      <div class="esc-col">
        <p class="etiqueta-grupo">Posibles causas</p>
        <p><?= e($es['causas']) ?></p>
        <p class="etiqueta-grupo">Síntomas</p>
        <div class="insignias-fila">
          <?php foreach ($es['sintomas'] as $s): ?><span class="chip-cuando mono"><?= e($s) ?></span><?php endforeach; ?>
        </div>
        <p class="etiqueta-grupo">Cómo confirmarlo</p>
        <pre class="script script-chico"><?= e($es['detectar']) ?></pre>
        <dl class="datos-lista">
          <dt>Pérdida de datos</dt><dd><?= e($es['perdida']) ?></dd>
          <dt>Tipo de recuperación</dt><dd><?= e($es['recuperacion']) ?></dd>
        </dl>
      </div>
      <div class="esc-col">
        <p class="etiqueta-grupo">Estrategia a considerar</p>
        <p><?= e($es['estrategia']) ?></p>
        <?php if ($es['cob']): ?>
          <div class="aviso <?= ['cubierto' => 'exito', 'parcial' => 'advertencia', 'sin' => 'error', 'config' => 'informacion', 'noaplica' => 'informacion'][$es['cob'][0]] ?>">
            <span class="titulo">En <?= e($base['nombre']) ?>: <?= e($etqCob[$es['cob'][0]][1]) ?></span>
            <p><?= e($es['cob'][1]) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="esc-instrucciones">
      <div class="esc-instr-cabeza">
        <p class="etiqueta-grupo">Instrucciones</p>
        <button type="button" class="boton boton-chico copiar" data-copiar="instr-<?= e($es['id']) ?>">Copiar</button>
      </div>
      <pre class="script" id="instr-<?= e($es['id']) ?>"><?= e($es['instrucciones']) ?></pre>
      <?php if ($es['nota'] !== ''): ?><p class="nota"><?= e($es['nota']) ?></p><?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
</div>

<script>
(function () {
  // Filtro por categoría (tabla y fichas)
  document.querySelectorAll('.chip-filtro').forEach(function (b) {
    b.addEventListener('click', function () {
      var cat = b.getAttribute('data-cat');
      document.querySelectorAll('.chip-filtro').forEach(function (x) { x.classList.toggle('activo', x === b); });
      document.querySelectorAll('[data-cat]').forEach(function (el) {
        if (el.classList.contains('chip-filtro')) return;
        el.hidden = cat !== '' && el.getAttribute('data-cat') !== cat;
      });
    });
  });
  // Abrir la ficha al llegar por enlace (#ESC-xx)
  function abrirHash() {
    var el = location.hash && document.getElementById(location.hash.slice(1));
    if (el && el.tagName === 'DETAILS') { el.open = true; }
  }
  window.addEventListener('hashchange', abrirHash); abrirHash();
  // Copiar instrucciones
  document.querySelectorAll('.copiar').forEach(function (b) {
    b.addEventListener('click', function () {
      var txt = document.getElementById(b.getAttribute('data-copiar')).textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () {
        b.textContent = 'Copiado ✓'; setTimeout(function () { b.textContent = 'Copiar'; }, 1500);
      }, function () { b.textContent = 'Seleccioná y copiá'; });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
$modoSim = function_exists('config') ? (bool) (config()['modo_simulacion'] ?? true) : null;
?>
</div></main>
<footer class="pie">
  <div class="container pie-inner">
    <div class="pie-marca">
      <svg class="marca-logo" viewBox="0 0 32 32" aria-hidden="true">
        <path class="escudo" d="M16 2.5 27 6.5v8.2c0 7-4.6 12.3-11 14.8C9.6 27 5 21.7 5 14.7V6.5Z"/>
        <ellipse class="bd" cx="16" cy="11.5" rx="5.5" ry="2"/>
        <path class="bd" d="M10.5 11.5v7.5c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2v-7.5"/>
        <path class="bd" d="M10.5 15.3c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2"/>
      </svg>
      <div>
        <strong>Backup<em>Guard</em></strong>
        <span>Gestión de estrategias de respaldo Oracle con RMAN</span>
      </div>
    </div>

    <ol class="pie-flujo" aria-label="Flujo de trabajo">
      <li><span>1</span>Estrategia</li>
      <li><span>2</span>Script RMAN</li>
      <li><span>3</span>Programación</li>
      <li><span>4</span>Ejecución</li>
      <li><span>5</span>Evidencia</li>
    </ol>
  </div>

  <div class="container pie-base">
    <span>EIF402 · Administración de Bases de Datos · Universidad Nacional · II ciclo <?= date('Y') ?></span>
    <?php if ($modoSim !== null): ?>
      <span class="pie-modo <?= $modoSim ? 'sim' : 'real' ?>"><?= $modoSim ? 'Modo simulación' : 'Modo real' ?></span>
    <?php endif; ?>
  </div>
</footer>
<script src="<?= asset_url('assets/js/ayuda.js') ?>"></script>
</body>
</html>

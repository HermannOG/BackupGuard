<?php
/**
 * Runner de BackupGuard — el EJECUTOR de las estrategias.
 *
 * Lee el catálogo de estrategias (MySQL), y para cada una pregunta lo mismo
 * que el ejecutor dibujado en la pizarra: ¿ya es el día y la hora? Si sí,
 * corre su EST###.rma con RMAN y guarda la evidencia (backup + log).
 *
 * Dos formas de usarlo:
 *
 *   php scripts/runner.php --loop [--intervalo=30]
 *       Agente residente: un ciclo infinito que revisa el catálogo cada
 *       N segundos (30 por omisión). Es el modelo pedido en clase:
 *           abrir catálogo → while (true) → ¿es el día? ¿es la hora? →
 *           ejecutar → al llegar al fin, volver a leer el catálogo.
 *       En Windows se arranca con iniciar-ejecutor.bat y se deja abierta
 *       la ventana. Ctrl+C lo detiene.
 *
 *   php scripts/runner.php
 *       Una sola pasada y termina. Para delegar el ciclo en el planificador
 *       del sistema: Task Scheduler (scripts/instalar-tarea-windows.bat),
 *       cron (scripts/crontab-ejemplo.txt) u Oracle Scheduler.
 *
 *   --dry-run   solo lista lo que ejecutaría, sin ejecutar nada.
 *
 * "¿Ya es la hora?" se resuelve con proxima_ejecucion <= ahora, no con una
 * igualdad exacta: si el ejecutor estaba ocupado justo en ese minuto, el
 * respaldo igual corre en la siguiente vuelta y no se pierde. Después de
 * correr se recalcula la próxima fecha, así que tampoco se repite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("El runner solo se ejecuta desde la línea de comandos.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/EstrategiaRepository.php';
require_once __DIR__ . '/../includes/Ejecutor.php';
require_once __DIR__ . '/../includes/Alertas.php';


function log_linea(string $texto): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $texto . PHP_EOL;
}

/**
 * Una vuelta completa por el catálogo. Devuelve cuántas estrategias ejecutó.
 */
function pasada(bool $soloListar): int
{
    $repo = new EstrategiaRepository();
    $pendientes = $repo->pendientesDeEjecutar();
    $ejecutadas = 0;

    foreach ($pendientes as $e) {
        $etiqueta = sprintf('%s "%s" sobre %s',
            RmanBuilder::codigo((int) $e['id']), $e['nombre'], $e['base_nombre']);

        if ($soloListar) {
            log_linea('PENDIENTE (dry-run): ' . $etiqueta .
                      ' programada para ' . $e['proxima_ejecucion']);
            continue;
        }

        log_linea('Es el día y la hora de ' . $etiqueta . ' — ejecutando RMAN...');
        try {
            $ejecutor = new Ejecutor();
            $ejecucionId = $ejecutor->ejecutar((int) $e['id'], 'programada');
            $ejecutadas++;

            $ej = $repo->obtenerEjecucion($ejecucionId);
            log_linea(sprintf('  → %s (%ss) — evidencia #%d',
                strtoupper($ej['resultado']), $ej['duracion_seg'] ?? '?', $ejecucionId));
            if ($ej['archivo_log']) {
                log_linea('  log: ' . $ej['archivo_log']);
            }
            if ($ej['resultado'] !== 'exitoso' && $ej['mensaje_error']) {
                log_linea('  ! ' . str_replace("\n", ' | ', $ej['mensaje_error']));
            }
        } catch (Throwable $ex) {
            log_linea('  ! ERROR: ' . $ex->getMessage());
        }
    }

    return $ejecutadas;
}

/** Re-evalúa el control preventivo: no solo cuando alguien abre el tablero. */
function evaluarAlertas(): void
{
    $alertas = new Alertas();
    $vigentes = $alertas->evaluar();
    $conteo = $alertas->conteo();
    log_linea(sprintf('Alertas vigentes: %d (críticas %d, advertencias %d, recomendaciones %d).',
        count($vigentes), $conteo['critica'], $conteo['advertencia'], $conteo['recomendacion']));
}

/** Próxima estrategia en el catálogo, para que el agente muestre que está vivo. */
function siguienteEnCatalogo(): string
{
    $fila = db()->query("
        SELECT id, nombre, proxima_ejecucion FROM estrategias
         WHERE estado = 'activa' AND aprobado = 1 AND proxima_ejecucion IS NOT NULL
         ORDER BY proxima_ejecucion LIMIT 1
    ")->fetch();

    return $fila
        ? sprintf('próxima: %s "%s" a las %s',
            RmanBuilder::codigo((int) $fila['id']), $fila['nombre'], $fila['proxima_ejecucion'])
        : 'no hay estrategias activas y aprobadas en el catálogo';
}

$soloListar = in_array('--dry-run', $argv, true);
$enCiclo    = in_array('--loop', $argv, true);
$intervalo  = 30;
foreach ($argv as $arg) {
    if (preg_match('/^--intervalo=(\d+)$/', $arg, $m)) {
        $intervalo = max(5, (int) $m[1]);
    }
}

// ----------------------------- Una pasada -----------------------------
if (!$enCiclo) {
    try {
        if (pasada($soloListar) === 0 && !$soloListar) {
            log_linea('Sin estrategias pendientes.');
        }
        evaluarAlertas();
        exit(0);
    } catch (Throwable $ex) {
        log_linea('FALLO DEL RUNNER: ' . $ex->getMessage());
        exit(1);
    }
}

// --------------------- Agente residente (ciclo infinito) --------------------
log_linea("Ejecutor de BackupGuard iniciado. Revisa el catálogo cada {$intervalo} s. Ctrl+C para detener.");
log_linea('Modo: ' . ((config()['modo_simulacion'] ?? true) ? 'SIMULACIÓN (no se invoca RMAN)' : 'REAL (invoca RMAN)'));

$ultimoEstado = '';
$ultimaRevisionAlertas = 0;

while (true) {
    try {
        $ejecutadas = pasada($soloListar);

        // Las alertas se re-evalúan después de ejecutar algo y, si no, cada
        // 10 minutos: no hace falta recalcularlas cada 30 segundos.
        if ($ejecutadas > 0 || time() - $ultimaRevisionAlertas >= 600) {
            evaluarAlertas();
            $ultimaRevisionAlertas = time();
        }

        // Una línea cuando cambia lo que viene, para no llenar la consola.
        $estado = siguienteEnCatalogo();
        if ($estado !== $ultimoEstado) {
            log_linea('Catálogo revisado — ' . $estado);
            $ultimoEstado = $estado;
        }
    } catch (Throwable $ex) {
        // Un fallo (por ejemplo MySQL caído) no detiene al agente: lo
        // reporta y vuelve a intentar en la siguiente vuelta.
        log_linea('! Fallo en esta vuelta: ' . $ex->getMessage());
    }

    sleep($intervalo);
}

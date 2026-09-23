<?php
/**
 * Runner de BackupGuard — el motor de la automatización.
 *
 * Revisa qué estrategias tienen su próxima ejecución vencida, las ejecuta y
 * vuelve a evaluar las alertas. No se queda residente: hace una pasada y sale,
 * así que hay que invocarlo periódicamente desde el planificador del sistema.
 *
 * Linux / Unix (cron, cada 5 minutos):
 *     cada 5 minutos -> ver scripts/crontab-ejemplo.txt
 *
 * Windows (Task Scheduler, repetir cada 5 minutos):
 *     php.exe C:\ruta\BackupGuard\scripts\runner.php
 *
 * Oracle Scheduler también sirve: creá un job de tipo EXECUTABLE que llame a
 * este mismo archivo, si preferís que la programación viva dentro de Oracle.
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

$soloListar = in_array('--dry-run', $argv, true);

try {
    $repo = new EstrategiaRepository();
    $pendientes = $repo->pendientesDeEjecutar();

    if (!$pendientes) {
        log_linea('Sin estrategias pendientes.');
    }

    foreach ($pendientes as $e) {
        $etiqueta = sprintf('#%d "%s" sobre %s', $e['id'], $e['nombre'], $e['base_nombre']);

        if ($soloListar) {
            log_linea('PENDIENTE (dry-run): ' . $etiqueta .
                      ' programada para ' . $e['proxima_ejecucion']);
            continue;
        }

        log_linea('Ejecutando ' . $etiqueta . '...');
        try {
            $ejecutor = new Ejecutor();
            $ejecucionId = $ejecutor->ejecutar((int) $e['id'], 'programada');

            $ej = $repo->obtenerEjecucion($ejecucionId);
            log_linea(sprintf('  → %s (%ss) — evidencia #%d',
                strtoupper($ej['resultado']), $ej['duracion_seg'] ?? '?', $ejecucionId));

            if ($ej['resultado'] !== 'exitoso' && $ej['mensaje_error']) {
                log_linea('  ! ' . str_replace("\n", ' | ', $ej['mensaje_error']));
            }
        } catch (Throwable $ex) {
            log_linea('  ! ERROR: ' . $ex->getMessage());
        }
    }

    // El control preventivo se re-evalúa en cada pasada, no solo cuando
    // alguien abre el tablero.
    $alertas = new Alertas();
    $vigentes = $alertas->evaluar();
    $conteo = $alertas->conteo();

    log_linea(sprintf('Alertas vigentes: %d (críticas %d, advertencias %d, recomendaciones %d).',
        count($vigentes), $conteo['critica'], $conteo['advertencia'], $conteo['recomendacion']));

    exit(0);

} catch (Throwable $ex) {
    log_linea('FALLO DEL RUNNER: ' . $ex->getMessage());
    exit(1);
}

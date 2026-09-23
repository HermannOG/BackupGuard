<?php
/**
 * Ejecutor — corre el script RMAN aprobado y guarda la evidencia.
 *
 * Reglas que sigue (tomadas del enunciado):
 *   - Solo ejecuta estrategias ACTIVAS con script APROBADO.
 *   - Guarda el script exacto que ejecutó, no el que estaba guardado después.
 *   - No asume éxito porque rman terminó: revisa el log buscando RMAN-/ORA-.
 *   - Comprueba que los archivos de respaldo existan cuando el destino es local.
 *
 * En modo simulación (includes/config.php) no invoca rman: genera una salida
 * verosímil y marca la ejecución como simulada. Sirve para desarrollar sin
 * Oracle y para demostrar un fallo controlado sin tocar ninguna base.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/oracle.php';
require_once __DIR__ . '/Programacion.php';
require_once __DIR__ . '/EstrategiaRepository.php';

class Ejecutor
{
    private PDO $pdo;
    private EstrategiaRepository $repo;
    private array $c;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo  = $pdo ?? db();
        $this->repo = new EstrategiaRepository($this->pdo);
        $this->c    = config();
    }

    /**
     * Ejecuta una estrategia y devuelve el id de la ejecución registrada.
     *
     * @param string $origen 'manual' o 'programada'
     * @param bool   $forzarFallo simula un error controlado (para evidencia)
     */
    public function ejecutar(int $estrategiaId, string $origen = 'manual', bool $forzarFallo = false): int
    {
        $e = $this->repo->obtener($estrategiaId);
        if (!$e) {
            throw new RuntimeException('Estrategia no encontrada.');
        }
        if ((int) $e['aprobado'] !== 1 || empty($e['script_rman'])) {
            throw new RuntimeException(
                'La estrategia no tiene un script aprobado. Generalo y aprobalo antes de ejecutar.'
            );
        }
        if ($e['estado'] !== 'activa' && $origen === 'programada') {
            throw new RuntimeException('La estrategia está inactiva.');
        }

        $bd = $this->repo->obtenerBase((int) $e['base_datos_id']);
        $script = $e['script_rman'];
        $inicio = new DateTimeImmutable('now');

        // Registro "en curso": si el proceso muere, queda la huella de que arrancó.
        $ejecucionId = $this->abrirEjecucion($e, $bd, $origen, $script, $inicio);

        try {
            $simular = (bool) ($this->c['modo_simulacion'] ?? true) || $forzarFallo;

            $r = $simular
                ? $this->correrSimulado($e, $bd, $script, $forzarFallo)
                : $this->correrRman($e, $bd, $script);

            $fin = new DateTimeImmutable('now');
            $duracion = $fin->getTimestamp() - $inicio->getTimestamp();

            $resultado = $this->clasificar($r['salida'], $r['codigo']);

            // Una ejecución que tardó más que la ventana acordada no es un
            // éxito limpio, aunque RMAN no haya reportado errores.
            $notas = [];
            if ($resultado === 'exitoso' && Programacion::excedeVentana($e, $duracion)) {
                $resultado = 'advertencia';
                $notas[] = 'La ejecución excedió la ventana de respaldo de ' .
                           (int) $e['ventana_minutos'] . ' minutos.';
            }

            // La existencia del archivo es parte de la evidencia, no un supuesto.
            // En una ejecución fallida no se cuenta nada: los archivos que
            // haya en el destino son de corridas anteriores, y atribuírselos
            // a esta daría una evidencia falsa.
            $conteo = $resultado === 'fallido' ? null : $this->contarArchivos($e, $inicio);
            if ($resultado === 'exitoso' && $conteo !== null && $conteo['archivos'] === 0) {
                $resultado = 'advertencia';
                $notas[] = 'RMAN terminó sin errores pero no se encontraron archivos nuevos en el destino.';
            }

            $this->cerrarEjecucion($ejecucionId, [
                'fin'          => $fin,
                'duracion'     => $duracion,
                'resultado'    => $resultado,
                'codigo'       => $r['codigo'],
                'salida'       => $r['salida'],
                'error'        => $this->extraerErrores($r['salida'], $notas),
                'ubicacion'    => $e['destino'] ?: 'Fast Recovery Area',
                'archivos'     => $conteo['archivos'] ?? null,
                'tamano'       => $conteo['bytes'] ?? null,
                'simulado'     => $simular ? 1 : 0,
            ]);

            // Avanzar la programación y marcar la última ejecución.
            $upd = $this->pdo->prepare("UPDATE estrategias SET ultima_ejecucion = :u WHERE id = :id");
            $upd->execute(['u' => $fin->format('Y-m-d H:i:s'), 'id' => $estrategiaId]);
            $this->repo->recalcularProxima($estrategiaId);

            bitacora('ejecucion', 'estrategias', $estrategiaId,
                'Resultado: ' . $resultado . ($simular ? ' (simulado)' : ''));

            return $ejecucionId;

        } catch (Throwable $ex) {
            $fin = new DateTimeImmutable('now');
            $this->cerrarEjecucion($ejecucionId, [
                'fin'       => $fin,
                'duracion'  => $fin->getTimestamp() - $inicio->getTimestamp(),
                'resultado' => 'fallido',
                'codigo'    => -1,
                'salida'    => null,
                'error'     => $ex->getMessage(),
                'ubicacion' => $e['destino'] ?: null,
                'archivos'  => null,
                'tamano'    => null,
                'simulado'  => 0,
            ]);
            $this->repo->recalcularProxima($estrategiaId);
            throw $ex;
        }
    }

    // ------------------------------------------------------------------

    private function abrirEjecucion(array $e, array $bd, string $origen, string $script, DateTimeImmutable $inicio): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ejecuciones
                (estrategia_id, base_datos_id, origen, tipo_respaldo, inicio,
                 resultado, script_ejecutado, ejecutado_por)
            VALUES (:e, :b, :o, :t, :i, 'en_curso', :s, :u)
        ");
        $stmt->execute([
            'e' => $e['id'],
            'b' => $bd['id'],
            'o' => $origen,
            't' => $e['tipo_respaldo'] . ($e['modalidad'] ? ' / ' . $e['modalidad'] : ''),
            'i' => $inicio->format('Y-m-d H:i:s'),
            's' => $script,
            'u' => $_SESSION['usuario']['nombre_usuario'] ?? ($origen === 'programada' ? 'scheduler' : null),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function cerrarEjecucion(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ejecuciones
               SET fin = :fin, duracion_seg = :dur, resultado = :res, codigo_salida = :cod,
                   salida_rman = :salida, mensaje_error = :err, ubicacion = :ubi,
                   archivos_generados = :arch, tamano_bytes = :tam, simulado = :sim
             WHERE id = :id
        ");
        $stmt->execute([
            'fin'    => $d['fin']->format('Y-m-d H:i:s'),
            'dur'    => $d['duracion'],
            'res'    => $d['resultado'],
            'cod'    => $d['codigo'],
            'salida' => $d['salida'],
            'err'    => $d['error'] ?: null,
            'ubi'    => $d['ubicacion'],
            'arch'   => $d['archivos'],
            'tam'    => $d['tamano'],
            'sim'    => $d['simulado'],
            'id'     => $id,
        ]);
    }

    /** Invoca rman de verdad con el script como cmdfile. */
    private function correrRman(array $e, array $bd, string $script): array
    {
        $dir = $this->carpetaTrabajo();
        $sello = date('Ymd_His') . '_' . $e['id'];
        $cmdfile = $dir . DIRECTORY_SEPARATOR . "bg_$sello.rman";
        $logfile = $dir . DIRECTORY_SEPARATOR . "bg_$sello.log";

        if (file_put_contents($cmdfile, $script) === false) {
            throw new RuntimeException('No se pudo escribir el archivo de comandos en ' . $dir);
        }

        $rman = $this->c['rman_bin'] ?? 'rman';
        $target = cadenaTargetRman($bd);

        // La contraseña va en la línea de comandos: se usa escapeshellarg y el
        // cmdfile se borra apenas termina. En un despliegue real conviene usar
        // un wallet de Oracle en vez de credenciales en claro.
        $comando = sprintf(
            '%s target %s cmdfile=%s log=%s',
            escapeshellarg($rman),
            escapeshellarg($target),
            escapeshellarg($cmdfile),
            escapeshellarg($logfile)
        );

        $salidaDirecta = [];
        $codigo = 0;
        exec($comando . ' 2>&1', $salidaDirecta, $codigo);

        $salida = is_readable($logfile)
            ? file_get_contents($logfile)
            : implode("\n", $salidaDirecta);

        @unlink($cmdfile);  // no dejar la contraseña ni el script suelto en disco

        return ['salida' => $salida, 'codigo' => $codigo];
    }

    /** Salida simulada: sirve para demos y para provocar un fallo controlado. */
    private function correrSimulado(array $e, array $bd, string $script, bool $forzarFallo): array
    {
        $fecha = date('d-M-y H:i:s');
        $l = [];
        $l[] = "Recovery Manager: Release 21.0.0.0.0 - Production on $fecha";
        $l[] = "";
        $l[] = "[BackupGuard] EJECUCIÓN SIMULADA — no se invocó rman ni se tocó ninguna base.";
        $l[] = "connected to target database: " . strtoupper($bd['nombre']) . " (DBID=1234567890)";
        $l[] = "";

        if ($forzarFallo) {
            $l[] = "Starting backup at $fecha";
            $l[] = "allocated channel: ch1";
            $l[] = "channel ch1: starting full datafile backup set";
            $l[] = "RMAN-00571: ===========================================================";
            $l[] = "RMAN-03009: failure of backup command on ch1 channel at $fecha";
            $l[] = "ORA-19809: limit exceeded for recovery files";
            $l[] = "ORA-19804: cannot reclaim 524288000 bytes disk space from db_recovery_file_dest_size";
            return ['salida' => implode("\n", $l), 'codigo' => 1];
        }

        $l[] = "Starting backup at $fecha";
        $l[] = "allocated channel: ch1";
        $l[] = "channel ch1: starting " . str_replace('_', ' ', $e['tipo_respaldo']) . " datafile backup set";
        $l[] = "channel ch1: backup set complete, elapsed time: 00:02:17";

        // La evidencia incluye la existencia del archivo, no solo el log. En
        // simulación se escriben piezas de marcador en el destino para que la
        // comprobación posterior (contarArchivos) tenga algo real que medir.
        foreach ($this->escribirPiezasSimuladas($e, $bd) as $ruta) {
            $l[] = "piece handle=$ruta tag=SIMULADO";
        }

        $l[] = "Finished backup at " . date('d-M-y H:i:s', time() + 137);
        $l[] = "";
        $l[] = "Starting Control File and SPFILE Autobackup at $fecha";
        $l[] = "Finished Control File and SPFILE Autobackup at $fecha";
        $l[] = "";
        $l[] = "Recovery Manager complete.";

        return ['salida' => implode("\n", $l), 'codigo' => 0];
    }

    /**
     * Escribe piezas de respaldo de marcador cuando la estrategia tiene un
     * destino en disco. NO son respaldos: son archivos de texto rotulados como
     * simulados, del tamaño suficiente para que el historial muestre un tamaño
     * verosímil. Si el destino es la FRA o no existe, no escribe nada.
     *
     * @return string[] rutas escritas
     */
    private function escribirPiezasSimuladas(array $e, array $bd): array
    {
        $destino = trim((string) ($e['destino'] ?? ''));
        if ($destino === '' || strtoupper($destino) === 'FRA') {
            return [];
        }
        $destino = rtrim($destino, '/\\');
        if (!is_dir($destino) && !@mkdir($destino, 0770, true)) {
            return [];
        }
        if (!is_writable($destino)) {
            return [];
        }

        $sello = date('Ymd_His');
        $piezas = ['bkp'];
        if ((int) ($e['incluir_spfile'] ?? 0) === 1)      { $piezas[] = 'spfile'; }
        if ((int) ($e['incluir_archivelogs'] ?? 0) === 1) { $piezas[] = 'arch'; }

        $rutas = [];
        foreach ($piezas as $i => $ext) {
            $ruta = sprintf('%s%sbg_%s_%s_%d_1.%s',
                $destino, DIRECTORY_SEPARATOR,
                preg_replace('/[^A-Za-z0-9]/', '', (string) $bd['nombre']),
                $sello, $i + 1, $ext
            );

            $contenido = "BackupGuard — PIEZA SIMULADA\n"
                . "Este archivo NO es un respaldo de Oracle.\n"
                . "Estrategia: " . ($e['nombre'] ?? '?') . "\n"
                . "Generado:   " . date('Y-m-d H:i:s') . "\n"
                . str_repeat("0", 64 * 1024);

            if (@file_put_contents($ruta, $contenido) !== false) {
                $rutas[] = $ruta;
            }
        }

        return $rutas;
    }

    /**
     * Clasifica el resultado leyendo el log.
     * No basta con el código de salida: RMAN puede terminar en 0 con errores
     * reportados en el log, y ese es justamente el caso que el enunciado pide
     * no dar por bueno.
     */
    private function clasificar(?string $salida, int $codigo): string
    {
        $texto = (string) $salida;

        if ($codigo !== 0) {
            return 'fallido';
        }
        if (preg_match('/RMAN-00569|RMAN-03009|RMAN-06059|ORA-0*(19809|19804|1157|1578)/i', $texto)) {
            return 'fallido';
        }
        if (preg_match('/\bRMAN-\d{5}\b/', $texto) || preg_match('/\bORA-\d{5}\b/', $texto)) {
            return 'advertencia';
        }
        if (stripos($texto, 'warning') !== false) {
            return 'advertencia';
        }
        if ($texto === '') {
            return 'advertencia';
        }
        return 'exitoso';
    }

    /** Junta los códigos de error encontrados + notas propias. */
    private function extraerErrores(?string $salida, array $notas = []): string
    {
        $mensajes = $notas;

        if ($salida) {
            foreach (explode("\n", $salida) as $linea) {
                if (preg_match('/\b(RMAN|ORA)-\d{4,5}\b/', $linea)) {
                    $mensajes[] = trim($linea);
                }
            }
        }

        $mensajes = array_slice(array_unique($mensajes), 0, 20);
        return implode("\n", $mensajes);
    }

    /** Cuenta archivos nuevos en el destino: evidencia física del respaldo. */
    private function contarArchivos(array $e, DateTimeImmutable $desde): ?array
    {
        $destino = trim((string) ($e['destino'] ?? ''));
        if ($destino === '' || strtoupper($destino) === 'FRA' || !is_dir($destino)) {
            return null;  // destino no inspeccionable desde el servidor web
        }

        $archivos = 0;
        $bytes = 0;
        foreach (glob(rtrim($destino, '/\\') . DIRECTORY_SEPARATOR . 'bg_*') ?: [] as $ruta) {
            if (is_file($ruta) && filemtime($ruta) >= $desde->getTimestamp() - 5) {
                $archivos++;
                $bytes += filesize($ruta);
            }
        }
        return ['archivos' => $archivos, 'bytes' => $bytes];
    }

    private function carpetaTrabajo(): string
    {
        $dir = $this->c['ruta_trabajo'] ?? (__DIR__ . '/../storage');
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new RuntimeException('No se pudo crear la carpeta de trabajo: ' . $dir);
        }
        return rtrim($dir, '/\\');
    }
}

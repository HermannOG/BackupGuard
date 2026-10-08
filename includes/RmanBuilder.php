<?php
/**
 * RmanBuilder — traduce una estrategia (QUÉ / CÓMO / CUÁNDO) en un script RMAN.
 *
 * Es la pieza central de BackupGuard: el administrador nunca escribe RMAN a
 * mano, describe la estrategia en la interfaz y esta clase produce el script
 * equivalente. El script se MUESTRA antes de ejecutarse y requiere aprobación
 * explícita: la herramienta propone, el administrador decide.
 *
 * Reglas de traducción (documentadas para el entregable de diseño):
 *
 *   alcance=base_completa      -> BACKUP ... DATABASE
 *   alcance=tablespaces        -> BACKUP ... TABLESPACE a, b, c
 *   alcance=datafiles          -> BACKUP ... DATAFILE 1, 4, 7
 *   tipo=completo              -> BACKUP AS BACKUPSET
 *   tipo=incremental_0         -> BACKUP INCREMENTAL LEVEL 0
 *   tipo=incremental_1 dif.    -> BACKUP INCREMENTAL LEVEL 1
 *   tipo=incremental_1 acum.   -> BACKUP INCREMENTAL LEVEL 1 CUMULATIVE
 *   comprimido                 -> AS COMPRESSED BACKUPSET
 *   paralelismo=n              -> n canales ALLOCATE CHANNEL
 *   dispositivo=disco          -> ALLOCATE CHANNEL ... DEVICE TYPE DISK
 *   dispositivo=cinta          -> ALLOCATE CHANNEL ... DEVICE TYPE SBT (media manager)
 *   incluir_controlfile        -> INCLUDE CURRENT CONTROLFILE
 *   incluir_spfile             -> BACKUP SPFILE
 *   incluir_archivelogs        -> PLUS ARCHIVELOG [DELETE INPUT]
 *   retencion_dias             -> DELETE OBSOLETE RECOVERY WINDOW OF n DAYS, solo
 *                                 DESPUÉS de verificar (no se usa CONFIGURE: cambiaría
 *                                 la política de toda la base y chocaría entre estrategias)
 *   verificar_respaldo         -> RESTORE ... VALIDATE (prueba de recuperabilidad)
 */
class RmanBuilder
{
    private array $e;   // fila de estrategias
    private array $bd;  // fila de bases_datos
    private array $objetos; // filas de estrategia_objetos

    public function __construct(array $estrategia, array $baseDatos, array $objetos = [])
    {
        $this->e = $estrategia;
        $this->bd = $baseDatos;
        $this->objetos = $objetos;
    }

    // =================================================================
    // VALIDACIÓN — corre ANTES de construir el script
    // =================================================================

    /**
     * Revisa la coherencia de la estrategia. Devuelve una lista de
     * ['nivel' => error|advertencia|recomendacion|informacion, 'mensaje' => ...].
     *
     * Nivel 'error' impide generar el script. Los demás niveles solo informan:
     * la decisión queda con el administrador, como exige el enunciado.
     */
    public function validar(): array
    {
        $avisos = [];
        $e = $this->e;
        $modo = $this->bd['modo_archivado'] ?? 'DESCONOCIDO';

        // --- Coherencia del QUÉ ---
        if ($e['alcance'] !== 'base_completa' && count($this->objetos) === 0) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'El alcance es "' . $e['alcance'] . '" pero no se seleccionó ningún objeto. ' .
                'Agregá al menos un tablespace o datafile, o cambiá el alcance a base completa.'];
        }

        // --- Coherencia del CÓMO ---
        if ($e['tipo_respaldo'] === 'incremental_1' && empty($e['modalidad'])) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'Un respaldo incremental nivel 1 debe indicar si es diferencial o acumulativo.'];
        }
        if ($e['tipo_respaldo'] !== 'incremental_1' && !empty($e['modalidad'])) {
            $avisos[] = ['nivel' => 'informacion', 'mensaje' =>
                'La modalidad diferencial/acumulativa solo aplica al nivel 1; se ignora en este tipo de respaldo.'];
        }
        if ((int) $e['paralelismo'] < 1 || (int) $e['paralelismo'] > 8) {
            $avisos[] = ['nivel' => 'error', 'mensaje' => 'El paralelismo debe estar entre 1 y 8 canales.'];
        }

        // --- Modo de archivado: el punto crítico del enunciado ---
        if ($modo === 'NOARCHIVELOG') {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'La base de datos se encuentra en modo NOARCHIVELOG. Las posibilidades de ' .
                'recuperación son más limitadas: no hay recuperación hasta un punto en el tiempo ' .
                'y el respaldo debe tomarse con la base cerrada (MOUNT). Revise la estrategia de ' .
                'respaldo y los requerimientos de recuperación antes de continuar.'];

            if ((int) $e['incluir_archivelogs'] === 1) {
                $avisos[] = ['nivel' => 'error', 'mensaje' =>
                    'La estrategia incluye archived redo logs, pero la base está en NOARCHIVELOG ' .
                    'y no los genera. Quitá esa opción o cambiá el modo de archivado de la base.'];
            }
            if ($e['tipo_respaldo'] !== 'completo') {
                $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                    'Una estrategia incremental sobre una base en NOARCHIVELOG solo permite ' .
                    'recuperar hasta el momento exacto de un respaldo, no hasta un punto intermedio.'];
            }
        } elseif ($modo === 'ARCHIVELOG') {
            if ((int) $e['incluir_archivelogs'] === 0) {
                $avisos[] = ['nivel' => 'recomendacion', 'mensaje' =>
                    'La base de datos se encuentra en modo ARCHIVELOG. Considere incorporar el ' .
                    'respaldo periódico de los archived redo logs dentro de la estrategia para ' .
                    'mejorar las posibilidades de recuperación.'];
            }
        } else {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'No se ha comprobado el modo de archivado de esta base. Verificá la conexión ' .
                'para que la herramienta pueda detectarlo antes de aprobar la estrategia.'];
        }

        if ((int) $e['borrar_archivelogs'] === 1 && (int) $e['incluir_archivelogs'] === 0) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'No se pueden borrar los archived redo logs (DELETE INPUT) sin incluirlos en el respaldo.'];
        }

        // --- Coherencia del CUÁNDO ---
        if ($e['estado'] === 'activa' && (empty($e['hora']) || empty($e['fecha_inicio']))) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'Una estrategia activa necesita fecha de inicio y hora de ejecución.'];
        }
        if ($e['frecuencia'] === 'semanal' && empty($e['dias_semana'])) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'Una frecuencia semanal requiere al menos un día de ejecución.'];
        }
        if ($e['frecuencia'] === 'mensual' && empty($e['dia_mes'])) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'Una frecuencia mensual requiere indicar el día del mes.'];
        }
        $intervalo = (int) ($e['intervalo'] ?? 1);
        if ($e['frecuencia'] !== 'unica' && $intervalo < 1) {
            $avisos[] = ['nivel' => 'error', 'mensaje' =>
                'El intervalo de repetición debe ser al menos 1.'];
        }
        if ($e['frecuencia'] === 'horas' && $intervalo >= 1 && !empty($e['ventana_minutos'])
            && (int) $e['ventana_minutos'] > $intervalo * 60) {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'La ventana de respaldo (' . (int) $e['ventana_minutos'] . ' min) es más larga que el intervalo ' .
                'entre ejecuciones (cada ' . $intervalo . ' h): una ejecución podría empezar antes de que ' .
                'termine la anterior. Aumentá el intervalo o reducí la ventana.'];
        }
        if ($e['frecuencia'] === 'horas' && $e['tipo_respaldo'] !== 'incremental_1' && $intervalo < 24) {
            $avisos[] = ['nivel' => 'recomendacion', 'mensaje' =>
                'Respaldar varias veces al día con un respaldo completo o de nivel 0 consume mucho espacio y ' .
                'tiempo. Para ejecuciones cada pocas horas suele convenir un incremental nivel 1 o solo los ' .
                'archived redo logs.'];
        }

        // --- Dispositivo ---
        if (($e['dispositivo'] ?? 'disco') === 'cinta') {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'El dispositivo es cinta (SBT). RMAN necesita una biblioteca de media management ' .
                'configurada (por ejemplo, Oracle Secure Backup); sin ella, el respaldo fallará. ' .
                'Confirmá que el servidor la tiene antes de aprobar.'];
        }
        if (array_key_exists('dispositivo_id', $e) && trim((string) $e['dispositivo_id']) === '') {
            $avisos[] = ['nivel' => 'informacion', 'mensaje' =>
                'No se identificó el dispositivo o almacenamiento. Indicarlo (por ejemplo, "Disco D: externo" ' .
                'o "NAS-BACKUP-01") deja claro en la evidencia dónde quedó cada respaldo.'];
        }

        if (!empty($e['retencion_dias']) && (int) $e['verificar_respaldo'] === 0) {
            $avisos[] = ['nivel' => 'recomendacion', 'mensaje' =>
                'La estrategia borra respaldos viejos por retención pero no verifica el nuevo. Si el respaldo ' .
                'de hoy saliera dañado, se habrían eliminado los anteriores que sí servían. Active la verificación ' .
                'para que la limpieza solo ocurra cuando el respaldo nuevo se pudo validar.'];
        }

        // --- Riesgo por ambiente ---
        if (($this->bd['ambiente'] ?? '') === 'produccion') {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'Esta base está marcada como PRODUCCIÓN. Probá el script en un ambiente de ' .
                'pruebas antes de aprobarlo.'];
        }

        // --- Destino ---
        if (($e['dispositivo'] ?? 'disco') === 'cinta') {
            // En cinta no hay carpeta: el media manager decide dónde se guarda.
        } elseif (empty($e['destino'])) {
            $avisos[] = ['nivel' => 'informacion', 'mensaje' =>
                'Sin destino explícito: el respaldo se escribirá en la Fast Recovery Area configurada en la base.'];
        } elseif (($esp = self::espacioDestino($e['destino'])) !== null) {
            $detalle = self::formatoEspacio($esp['libre']) . ' libres de ' .
                       self::formatoEspacio($esp['total']) . ' (' . $esp['pct_libre'] . '%)';
            if (self::nivelEspacio($esp) !== null) {
                $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                    'Queda poco espacio en el destino del respaldo: ' . $detalle . '. ' .
                    'Un respaldo puede fallar a medias por falta de espacio; libere espacio o elija otro destino.'];
            } else {
                $avisos[] = ['nivel' => 'informacion', 'mensaje' =>
                    'Espacio disponible en el destino: ' . $detalle . '.'];
            }
        }

        return $avisos;
    }

    // =================================================================
    // ESPACIO EN EL DESTINO
    // =================================================================

    /**
     * Espacio libre del volumen donde caerá el respaldo. Devuelve null cuando
     * no se puede medir (sin destino, FRA, ruta inaccesible): en ese caso no
     * se avisa nada en vez de inventar un dato.
     *
     * Si la carpeta aún no existe (la crea la primera ejecución), se mide el
     * primer directorio existente hacia arriba, que está en el mismo volumen.
     * Solo ve discos que el servidor de la aplicación puede leer: en un
     * destino de red o en otro equipo puede no haber dato.
     */
    public static function espacioDestino(?string $destino): ?array
    {
        $destino = trim((string) $destino);
        if ($destino === '' || strtoupper($destino) === 'FRA') {
            return null;
        }
        // Una ruta relativa se interpretaría desde otro proceso (RMAN/Oracle):
        // medirla desde PHP daría el espacio de un disco equivocado.
        if (!preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $destino)) {
            return null;
        }

        $dir = rtrim($destino, '/\\');
        for ($i = 0; $i < 32 && $dir !== '' && !is_dir($dir); $i++) {
            $padre = dirname($dir);
            if ($padre === $dir) { break; }
            $dir = $padre;
        }
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }

        $libre = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($libre === false || $total === false || $total <= 0) {
            return null;
        }

        return [
            'libre'     => (int) $libre,
            'total'     => (int) $total,
            'pct_libre' => round($libre / $total * 100, 1),
            'ruta'      => $dir,
        ];
    }

    /**
     * Umbrales únicos para la validación y para la alerta:
     *   crítica      menos del 5 % libre, o menos de 500 MB
     *   advertencia  menos del 10 % libre, o menos de 2 GB
     */
    public static function nivelEspacio(array $esp): ?string
    {
        if ($esp['pct_libre'] < 5 || $esp['libre'] < 500 * 1048576) {
            return 'critica';
        }
        if ($esp['pct_libre'] < 10 || $esp['libre'] < 2 * 1073741824) {
            return 'advertencia';
        }
        return null;
    }

    private static function formatoEspacio(int $bytes): string
    {
        return $bytes >= 1073741824
            ? round($bytes / 1073741824, 1) . ' GB'
            : round($bytes / 1048576) . ' MB';
    }

    public function tieneErrores(): bool
    {
        foreach ($this->validar() as $a) {
            if ($a['nivel'] === 'error') { return true; }
        }
        return false;
    }

    // =================================================================
    // CONSTRUCCIÓN DEL SCRIPT
    // =================================================================

    public function construir(): string
    {
        if ($this->tieneErrores()) {
            throw new RuntimeException(
                'La estrategia tiene errores de validación; corregilos antes de generar el script.'
            );
        }

        $e = $this->e;
        $l = [];   // líneas del script

        // ---- Encabezado: el script debe poder leerse solo ----
        $l[] = '# =====================================================================';
        $l[] = '# Script RMAN generado por BackupGuard';
        $l[] = '# Estrategia : ' . (isset($e['id']) ? self::codigo((int) $e['id']) . ' - ' : '') . $e['nombre'];
        $l[] = '# Base       : ' . $this->bd['nombre'] . ' [' . strtoupper($this->bd['ambiente']) . ']';
        $l[] = '# Archivado  : ' . $this->bd['modo_archivado'];
        $l[] = '# Prioridad  : ' . strtoupper($e['prioridad']);
        $l[] = '# Tipo       : ' . $this->etiquetaTipo();
        $l[] = '# Dispositivo: ' . $this->tipoDispositivo()
             . (trim((string) ($e['dispositivo_id'] ?? '')) !== '' ? ' — ' . trim((string) $e['dispositivo_id']) : '');
        $l[] = '# Generado   : ' . date('Y-m-d H:i:s');
        $l[] = '# Revisá este script antes de aprobarlo. BackupGuard no lo ejecuta sin aprobación.';
        $l[] = '# =====================================================================';
        $l[] = '';

        // ---- Bloque RUN ----
        $l[] = 'RUN {';

        $paralelismo = max(1, (int) $e['paralelismo']);
        for ($i = 1; $i <= $paralelismo; $i++) {
            $l[] = sprintf("  ALLOCATE CHANNEL ch%d DEVICE TYPE %s;", $i, $this->tipoDispositivo());
        }
        $l[] = '';

        // Comando BACKUP principal
        foreach ($this->lineasBackup() as $linea) {
            $l[] = '  ' . $linea;
        }
        $l[] = '';

        // SPFILE y control file autónomo
        if ((int) $e['incluir_spfile'] === 1) {
            $l[] = '  BACKUP SPFILE' . $this->formatoSufijo('spfile') . ';';
        }
        if ((int) $e['incluir_controlfile'] === 1 && $e['alcance'] !== 'base_completa') {
            // En base completa ya va con INCLUDE CURRENT CONTROLFILE.
            $l[] = '  BACKUP CURRENT CONTROLFILE' . $this->formatoSufijo('ctl') . ';';
        }

        for ($i = 1; $i <= $paralelismo; $i++) {
            $l[] = sprintf("  RELEASE CHANNEL ch%d;", $i);
        }
        $l[] = '}';

        // ---- Verificación: un respaldo sin verificar no es evidencia ----
        if ((int) $e['verificar_respaldo'] === 1) {
            $l[] = '';
            $l[] = '# Verificación: comprueba que lo respaldado sirve para restaurar.';
            $l[] = '# No modifica la base; solo lee los respaldos y reporta bloques corruptos.';
            // RESTORE ... VALIDATE elige los respaldos que usaría una
            // restauración real y los lee completos. (VALIDATE BACKUPSET exige
            // números de backupset concretos; "ALL" no es sintaxis válida.)
            // En cinta hay que indicar el dispositivo: por defecto RMAN busca en disco.
            $dev = $this->tipoDispositivo() === 'SBT' ? ' DEVICE TYPE SBT' : '';
            $l[] = 'RESTORE ' . $this->objetoBackup() . ' VALIDATE' . $dev . ';';
            if ((int) $e['incluir_controlfile'] === 1) {
                $l[] = 'RESTORE CONTROLFILE VALIDATE' . $dev . ';';
            }
            if ((int) $e['incluir_spfile'] === 1) {
                $l[] = 'RESTORE SPFILE VALIDATE' . $dev . ';';
            }
        }

        // ---- Limpieza según retención: SIEMPRE después de verificar ----
        // RMAN detiene el script si un comando falla: si la verificación
        // no pasa, nunca se borran los respaldos anteriores que sí sirven.
        // La ventana va en el propio comando (no CONFIGURE), así cada
        // estrategia aplica la suya sin cambiar la política de toda la base.
        $ventana = !empty($e['retencion_dias'])
            ? ' RECOVERY WINDOW OF ' . (int) $e['retencion_dias'] . ' DAYS'
            : '';
        if ($ventana !== '') {
            $l[] = '';
            $l[] = (int) $e['verificar_respaldo'] === 1
                ? '# Limpieza: solo se llega aquí si la verificación pasó.'
                : '# Limpieza de respaldos fuera de la ventana de retención.';
            if ($this->tipoDispositivo() === 'SBT') {
                $l[] = 'ALLOCATE CHANNEL FOR MAINTENANCE DEVICE TYPE SBT;';
            }
            $l[] = 'CROSSCHECK BACKUP;';
            $l[] = 'DELETE NOPROMPT EXPIRED BACKUP;';
            $l[] = 'DELETE NOPROMPT OBSOLETE' . $ventana . ';';
            if ($this->tipoDispositivo() === 'SBT') {
                $l[] = 'RELEASE CHANNEL;';
            }
        }

        // ---- Reporte final: queda en el log y se guarda como evidencia ----
        $l[] = '';
        $l[] = '# Reporte para la evidencia de ejecución.';
        $l[] = 'LIST BACKUP SUMMARY;';
        $l[] = 'REPORT NEED BACKUP' . $ventana . ';';
        $l[] = '';
        $l[] = 'EXIT;';

        return implode("\n", $l) . "\n";
    }

    /** Comando BACKUP principal, ya armado por partes. */
    private function lineasBackup(): array
    {
        $e = $this->e;
        $partes = ['BACKUP'];

        // Tipo / nivel
        if ($e['tipo_respaldo'] === 'incremental_0') {
            $partes[] = 'INCREMENTAL LEVEL 0';
        } elseif ($e['tipo_respaldo'] === 'incremental_1') {
            $partes[] = 'INCREMENTAL LEVEL 1';
            if ($e['modalidad'] === 'acumulativo') {
                $partes[] = 'CUMULATIVE';
            }
        }

        // Compresión
        $partes[] = (int) $e['comprimido'] === 1 ? 'AS COMPRESSED BACKSET_PLACEHOLDER' : 'AS BACKSET_PLACEHOLDER';

        // Objeto
        $partes[] = $this->objetoBackup();

        $comando = str_replace('BACKSET_PLACEHOLDER', 'BACKUPSET', implode(' ', $partes));

        // Control file dentro del respaldo de base completa
        if ($e['alcance'] === 'base_completa' && (int) $e['incluir_controlfile'] === 1) {
            $comando .= ' INCLUDE CURRENT CONTROLFILE';
        }

        // Etiqueta + formato
        $comando .= " TAG '" . $this->tag() . "'";
        $comando .= $this->formatoSufijo('bkp');

        // Archived redo logs
        if ((int) $e['incluir_archivelogs'] === 1) {
            $comando .= ' PLUS ARCHIVELOG';
            if ((int) $e['borrar_archivelogs'] === 1) {
                $comando .= ' DELETE INPUT';
            }
        }

        return [$comando . ';'];
    }

    private function objetoBackup(): string
    {
        $e = $this->e;

        if ($e['alcance'] === 'base_completa') {
            return 'DATABASE';
        }

        $nombres = [];
        foreach ($this->objetos as $o) {
            if ($e['alcance'] === 'tablespaces' && $o['tipo'] === 'tablespace') {
                $nombres[] = strtoupper($o['nombre']);
            } elseif ($e['alcance'] === 'datafiles' && $o['tipo'] === 'datafile') {
                // Puede ser número de datafile o ruta; las rutas van entre comillas.
                $nombres[] = ctype_digit(trim($o['nombre']))
                    ? trim($o['nombre'])
                    : "'" . str_replace("'", "''", trim($o['nombre'])) . "'";
            }
        }

        $palabra = $e['alcance'] === 'tablespaces' ? 'TABLESPACE' : 'DATAFILE';
        return $palabra . ' ' . implode(', ', $nombres);
    }

    /** Sufijo FORMAT, solo si hay destino explícito (si no, va a la FRA). */
    private function formatoSufijo(string $extension): string
    {
        $prefijo = isset($this->e['id']) ? self::codigo((int) $this->e['id']) : 'EST';
        // En cinta no hay rutas: solo se nombra la pieza; el media manager la ubica.
        if (($this->e['dispositivo'] ?? 'disco') === 'cinta') {
            return " FORMAT '" . $prefijo . '_%d_%T_%s_%p.' . $extension . "'";
        }
        $destino = trim((string) ($this->e['destino'] ?? ''));
        if ($destino === '' || strtoupper($destino) === 'FRA') {
            return '';
        }
        $destino = rtrim($destino, '/\\');
        $sep = str_contains($destino, '\\') ? '\\' : '/';
        // Las piezas llevan el código de la estrategia: en la carpeta se ve
        // de un vistazo qué estrategia produjo cada archivo.
        return " FORMAT '" . $destino . $sep . $prefijo . '_%d_%T_%s_%p.' . $extension . "'";
    }

    /** Tipo de dispositivo RMAN: DISK (disco local o de red) o SBT (cinta / media manager). */
    private function tipoDispositivo(): string
    {
        return ($this->e['dispositivo'] ?? 'disco') === 'cinta' ? 'SBT' : 'DISK';
    }

    // =================================================================
    // PLAN DE RECUPERACIÓN — se muestra, nunca se ejecuta desde aquí
    // =================================================================

    /**
     * Qué respaldos hacen falta y qué instrucciones RMAN restaurarían lo que
     * protege esta estrategia. Responde a la sección 14 del enunciado: la
     * estrategia debe considerar también la posibilidad posterior de
     * recuperación. Las operaciones de recuperación son destructivas y solo
     * deben correrse en un ambiente controlado, por eso BackupGuard solo las
     * muestra.
     *
     * @return array{necesarios: string[], script: string, notas: string[]}
     */
    public function planRecuperacion(): array
    {
        $e = $this->e;
        $archivelog = ($this->bd['modo_archivado'] ?? '') === 'ARCHIVELOG';
        $noArchivelog = ($this->bd['modo_archivado'] ?? '') === 'NOARCHIVELOG';
        $dev = $this->tipoDispositivo();

        // ---- Qué respaldos se necesitan
        $necesarios = match ($e['tipo_respaldo']) {
            'completo' => ['El último respaldo completo de esta estrategia.'],
            'incremental_0' => ['El último respaldo incremental de nivel 0.'],
            'incremental_1' => ($e['modalidad'] ?? '') === 'acumulativo'
                ? ['Un respaldo incremental de nivel 0 (de otra estrategia de la misma base).',
                   'Solo el último incremental acumulativo posterior a ese nivel 0.']
                : ['Un respaldo incremental de nivel 0 (de otra estrategia de la misma base).',
                   'Todos los incrementales diferenciales posteriores a ese nivel 0, en orden.'],
            default => ['El último respaldo de la estrategia.'],
        };
        if ($archivelog) {
            $necesarios[] = 'Los archived redo logs generados después del respaldo, para llevar la base hasta el último cambio o hasta un momento exacto.';
        }
        if ((int) $e['incluir_controlfile'] === 1) {
            $necesarios[] = 'El respaldo del control file, si se perdió el actual.';
        }
        if ((int) $e['incluir_spfile'] === 1) {
            $necesarios[] = 'El respaldo del SPFILE, si se perdió el archivo de parámetros.';
        }

        // ---- Script de restauración
        $l = [];
        $l[] = '# =====================================================================';
        $l[] = '# Plan de recuperación — ' . (isset($e['id']) ? self::codigo((int) $e['id']) . ' - ' : '') . $e['nombre'];
        $l[] = '# BackupGuard NO ejecuta este script. Las operaciones de recuperación';
        $l[] = '# solo deben realizarse en un ambiente controlado (enunciado, sección 14).';
        $l[] = '# =====================================================================';
        $l[] = '';
        $l[] = '# Paso previo, sin riesgo: muestra qué respaldos usaría RMAN, sin restaurar nada.';
        $l[] = 'RESTORE ' . $this->objetoBackup() . ' PREVIEW;';
        $l[] = '';

        if ($e['alcance'] === 'base_completa') {
            if ($noArchivelog) {
                // Sin archived logs, la base vuelve al estado exacto del
                // respaldo: se restaura también el control file de ese momento.
                $l[] = '# 1. Restaurar el control file del respaldo y montar la base.';
                $l[] = 'SHUTDOWN IMMEDIATE;';
                $l[] = 'STARTUP NOMOUNT;';
                $l[] = 'RESTORE CONTROLFILE FROM AUTOBACKUP;';
                $l[] = 'ALTER DATABASE MOUNT;';
            } else {
                $l[] = '# 1. La base debe estar montada (no abierta) para restaurarla completa.';
                $l[] = 'SHUTDOWN IMMEDIATE;';
                $l[] = 'STARTUP MOUNT;';
            }
            $l[] = '';
            $l[] = 'RUN {';
            $l[] = "  ALLOCATE CHANNEL ch1 DEVICE TYPE $dev;";
            if ($archivelog) {
                $l[] = "  # Opcional: para volver a un momento exacto, descomentar y ajustar.";
                $l[] = "  # SET UNTIL TIME \"TO_DATE('" . date('Y-m-d') . " 08:00','YYYY-MM-DD HH24:MI')\";";
            }
            $l[] = '  RESTORE DATABASE;';
            $l[] = $noArchivelog
                ? '  RECOVER DATABASE NOREDO;   # sin archived logs: se vuelve al momento del respaldo'
                : '  RECOVER DATABASE;          # aplica los incrementales y los archived redo logs';
            $l[] = '  RELEASE CHANNEL ch1;';
            $l[] = '}';
            $l[] = '';
            $l[] = '# 2. Abrir la base.';
            $l[] = $noArchivelog ? 'ALTER DATABASE OPEN RESETLOGS;' : 'ALTER DATABASE OPEN;';
            $l[] = '# Si se usó SET UNTIL TIME, abrir con: ALTER DATABASE OPEN RESETLOGS;';
        } else {
            $objetos = array_map(fn($o) => trim($o['nombre']), $this->objetos);
            $esTs = $e['alcance'] === 'tablespaces';
            $l[] = $esTs
                ? '# La base sigue abierta: solo se restauran los tablespaces afectados.'
                : '# La base sigue abierta: solo se restauran los datafiles afectados.';
            foreach ($objetos as $o) {
                $l[] = $esTs
                    ? 'ALTER TABLESPACE ' . strtoupper($o) . ' OFFLINE IMMEDIATE;'
                    : 'ALTER DATABASE DATAFILE ' . (ctype_digit($o) ? $o : "'" . $o . "'") . ' OFFLINE;';
            }
            $l[] = '';
            $l[] = 'RUN {';
            $l[] = "  ALLOCATE CHANNEL ch1 DEVICE TYPE $dev;";
            $l[] = '  RESTORE ' . $this->objetoBackup() . ';';
            $l[] = '  RECOVER ' . $this->objetoBackup() . ';';
            $l[] = '  RELEASE CHANNEL ch1;';
            $l[] = '}';
            $l[] = '';
            foreach ($objetos as $o) {
                $l[] = $esTs
                    ? 'ALTER TABLESPACE ' . strtoupper($o) . ' ONLINE;'
                    : 'ALTER DATABASE DATAFILE ' . (ctype_digit($o) ? $o : "'" . $o . "'") . ' ONLINE;';
            }
        }

        // ---- Notas
        $notas = [];
        if ($noArchivelog) {
            $notas[] = 'La base está en NOARCHIVELOG: solo se puede volver al momento exacto del respaldo. Todo cambio posterior se pierde.';
        } elseif ($archivelog) {
            $notas[] = 'La base está en ARCHIVELOG: además de volver al último cambio, se puede recuperar hasta un momento exacto con SET UNTIL TIME.';
        } else {
            $notas[] = 'No se conoce el modo de archivado de la base: verificala para saber hasta dónde se puede recuperar.';
        }
        if ($e['alcance'] !== 'base_completa' && !$archivelog) {
            $notas[] = 'Restaurar tablespaces o datafiles con la base abierta requiere modo ARCHIVELOG.';
        }
        if ($e['tipo_respaldo'] === 'incremental_1') {
            $notas[] = 'Esta estrategia por sí sola no alcanza para restaurar: necesita un nivel 0 de otra estrategia de la misma base.';
        }
        $notas[] = 'RMAN elige automáticamente las piezas de respaldo necesarias; RESTORE ... PREVIEW permite comprobarlo antes.';

        return ['necesarios' => $necesarios, 'script' => implode("\n", $l), 'notas' => $notas];
    }

    /** Código de catálogo de una estrategia: EST001, EST002… (también nombre del .rma). */
    public static function codigo(int $id): string
    {
        return sprintf('EST%03d', $id);
    }

    /**
     * Texto del script tal como se escribe en el archivo .rma. RMAN en Windows
     * lee el archivo con la página de códigos del sistema (Windows-1252); en
     * UTF-8 los comentarios con tildes le llegarían alterados.
     */
    public static function paraArchivo(string $script): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? mb_convert_encoding($script, 'Windows-1252', 'UTF-8')
            : $script;
    }

    /** Etiqueta RMAN: permite ubicar después qué estrategia produjo el respaldo. */
    /**
     * TAG del respaldo: código de la estrategia + tipo (EST001_INC0, EST002_INC1D…).
     * Coincide con el nombre del archivo EST###.rma y con las piezas en disco,
     * así se reconoce de un vistazo qué estrategia produjo cada respaldo.
     */
    private function tag(): string
    {
        $codigo = isset($this->e['id']) ? self::codigo((int) $this->e['id']) : 'EST';
        $tipo = match ($this->e['tipo_respaldo']) {
            'completo'      => 'FULL',
            'incremental_0' => 'INC0',
            'incremental_1' => ($this->e['modalidad'] ?? '') === 'acumulativo' ? 'INC1A' : 'INC1D',
            default         => 'BKP',
        };
        return $codigo . '_' . $tipo;
    }

    public function etiquetaTipo(): string
    {
        $e = $this->e;
        return match ($e['tipo_respaldo']) {
            'completo'      => 'Respaldo completo',
            'incremental_0' => 'Incremental nivel 0',
            'incremental_1' => 'Incremental nivel 1 ' .
                               ($e['modalidad'] === 'acumulativo' ? '(acumulativo)' : '(diferencial)'),
            default         => $e['tipo_respaldo'],
        };
    }

    /**
     * Explica en lenguaje natural cómo se tradujo la estrategia al script.
     * Se muestra junto al script para que la traducción sea auditable.
     */
    public function explicarTraduccion(): array
    {
        $e = $this->e;
        $pasos = [];

        $pasos[] = ['QUÉ', match ($e['alcance']) {
            'base_completa' => 'Base completa → BACKUP ... DATABASE',
            'tablespaces'   => count($this->objetos) . ' tablespace(s) → BACKUP ... TABLESPACE',
            'datafiles'     => count($this->objetos) . ' datafile(s) → BACKUP ... DATAFILE',
            default         => $e['alcance'],
        }];

        $extras = [];
        if ((int) $e['incluir_controlfile'] === 1) { $extras[] = 'control file'; }
        if ((int) $e['incluir_spfile'] === 1)      { $extras[] = 'SPFILE'; }
        if ((int) $e['incluir_archivelogs'] === 1) { $extras[] = 'archived redo logs'; }
        if ($extras) {
            $pasos[] = ['QUÉ', 'Además se incluye: ' . implode(', ', $extras) . '.'];
        }

        $pasos[] = ['CÓMO', $this->etiquetaTipo() . ' → ' . match ($e['tipo_respaldo']) {
            'completo'      => 'BACKUP AS BACKUPSET',
            'incremental_0' => 'BACKUP INCREMENTAL LEVEL 0',
            'incremental_1' => 'BACKUP INCREMENTAL LEVEL 1' .
                               ($e['modalidad'] === 'acumulativo' ? ' CUMULATIVE' : ''),
            default         => '',
        }];
        if ((int) $e['comprimido'] === 1) {
            $pasos[] = ['CÓMO', 'Compresión activada → AS COMPRESSED BACKUPSET'];
        }
        $pasos[] = ['CÓMO', 'Paralelismo ' . (int) $e['paralelismo'] . ' → ' .
                            (int) $e['paralelismo'] . ' canal(es) ALLOCATE CHANNEL'];
        if ((int) $e['verificar_respaldo'] === 1) {
            $pasos[] = ['CÓMO', 'Verificación activada → RESTORE ... VALIDATE (datos, control file y SPFILE)'];
        }
        if (!empty($e['retencion_dias'])) {
            $pasos[] = ['CÓMO', 'Retención de ' . (int) $e['retencion_dias'] . ' días → ' .
                                'DELETE OBSOLETE RECOVERY WINDOW OF ' . (int) $e['retencion_dias'] .
                                ' DAYS, después de verificar'];
        }

        $pasos[] = ['CUÁNDO', Programacion::describir($e)];
        $disp = trim((string) ($e['dispositivo_id'] ?? ''));
        if (($e['dispositivo'] ?? 'disco') === 'cinta') {
            $pasos[] = ['DESTINO', 'Cinta → DEVICE TYPE SBT (media manager)' . ($disp !== '' ? ' · ' . $disp : '')];
        } else {
            $pasos[] = ['DESTINO', 'Disco → DEVICE TYPE DISK' . ($disp !== '' ? ' · ' . $disp : '')];
            $pasos[] = ['DESTINO', trim((string) $e['destino']) === ''
                ? 'Fast Recovery Area de la base (sin cláusula FORMAT)'
                : 'FORMAT hacia ' . $e['destino']];
        }

        return $pasos;
    }
}
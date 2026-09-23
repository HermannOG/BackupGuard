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
 *   incluir_controlfile        -> INCLUDE CURRENT CONTROLFILE
 *   incluir_spfile             -> BACKUP SPFILE
 *   incluir_archivelogs        -> PLUS ARCHIVELOG [DELETE INPUT]
 *   retencion_dias             -> CONFIGURE RETENTION POLICY + DELETE OBSOLETE
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

        // --- Riesgo por ambiente ---
        if (($this->bd['ambiente'] ?? '') === 'produccion') {
            $avisos[] = ['nivel' => 'advertencia', 'mensaje' =>
                'Esta base está marcada como PRODUCCIÓN. Probá el script en un ambiente de ' .
                'pruebas antes de aprobarlo.'];
        }

        // --- Destino ---
        if (empty($e['destino'])) {
            $avisos[] = ['nivel' => 'informacion', 'mensaje' =>
                'Sin destino explícito: el respaldo se escribirá en la Fast Recovery Area configurada en la base.'];
        }

        return $avisos;
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
        $l[] = '# Estrategia : ' . $e['nombre'] . ' (id ' . ($e['id'] ?? 's/n') . ')';
        $l[] = '# Base       : ' . $this->bd['nombre'] . ' [' . strtoupper($this->bd['ambiente']) . ']';
        $l[] = '# Archivado  : ' . $this->bd['modo_archivado'];
        $l[] = '# Prioridad  : ' . strtoupper($e['prioridad']);
        $l[] = '# Tipo       : ' . $this->etiquetaTipo();
        $l[] = '# Generado   : ' . date('Y-m-d H:i:s');
        $l[] = '# Revisá este script antes de aprobarlo. BackupGuard no lo ejecuta sin aprobación.';
        $l[] = '# =====================================================================';
        $l[] = '';

        // ---- Política de retención ----
        if (!empty($e['retencion_dias'])) {
            $l[] = 'CONFIGURE RETENTION POLICY TO RECOVERY WINDOW OF ' . (int) $e['retencion_dias'] . ' DAYS;';
            $l[] = '';
        }

        // ---- Bloque RUN ----
        $l[] = 'RUN {';

        $paralelismo = max(1, (int) $e['paralelismo']);
        for ($i = 1; $i <= $paralelismo; $i++) {
            $l[] = sprintf("  ALLOCATE CHANNEL ch%d DEVICE TYPE DISK;", $i);
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

        // Limpieza según retención
        if (!empty($e['retencion_dias'])) {
            $l[] = '';
            $l[] = '  CROSSCHECK BACKUP;';
            $l[] = '  DELETE NOPROMPT EXPIRED BACKUP;';
            $l[] = '  DELETE NOPROMPT OBSOLETE;';
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
            $l[] = 'VALIDATE BACKUPSET ALL;';
            if ($e['alcance'] === 'base_completa') {
                $l[] = 'RESTORE DATABASE VALIDATE;';
                if ((int) $e['incluir_controlfile'] === 1) {
                    $l[] = 'RESTORE CONTROLFILE VALIDATE;';
                }
            }
        }

        // ---- Reporte final: queda en el log y se guarda como evidencia ----
        $l[] = '';
        $l[] = '# Reporte para la evidencia de ejecución.';
        $l[] = 'LIST BACKUP SUMMARY;';
        $l[] = 'REPORT NEED BACKUP;';
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
        $destino = trim((string) ($this->e['destino'] ?? ''));
        if ($destino === '' || strtoupper($destino) === 'FRA') {
            return '';
        }
        $destino = rtrim($destino, '/\\');
        $sep = str_contains($destino, '\\') ? '\\' : '/';
        return " FORMAT '" . $destino . $sep . 'bg_%d_%T_%s_%p.' . $extension . "'";
    }

    /** Etiqueta RMAN: permite ubicar después qué estrategia produjo el respaldo. */
    private function tag(): string
    {
        $base = 'BG_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($this->e['nombre']));
        $base = substr($base, 0, 20);
        return $base . '_' . strtoupper(substr($this->e['tipo_respaldo'], 0, 6));
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
            $pasos[] = ['CÓMO', 'Verificación activada → VALIDATE BACKUPSET ALL + RESTORE ... VALIDATE'];
        }
        if (!empty($e['retencion_dias'])) {
            $pasos[] = ['CÓMO', 'Retención de ' . (int) $e['retencion_dias'] . ' días → ' .
                                'CONFIGURE RETENTION POLICY + DELETE OBSOLETE'];
        }

        $pasos[] = ['CUÁNDO', Programacion::describir($e)];
        $pasos[] = ['DESTINO', trim((string) $e['destino']) === ''
            ? 'Fast Recovery Area de la base (sin cláusula FORMAT)'
            : 'FORMAT hacia ' . $e['destino']];

        return $pasos;
    }
}

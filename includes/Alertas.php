<?php
/**
 * Alertas — el control preventivo propiamente dicho.
 *
 * Revisa el estado del sistema y levanta avisos ANTES de que la falta de un
 * respaldo se convierta en un problema. Cada regla corresponde a una de las
 * condiciones que el enunciado pide detectar (sección 11).
 *
 * Se re-evalúa en cada carga del tablero y en cada corrida del runner. Las
 * alertas del mismo código sobre la misma entidad se reemplazan en vez de
 * duplicarse, para que la lista refleje el estado actual y no un histórico.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Programacion.php';

class Alertas
{
    private PDO $pdo;
    private array $detectadas = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /** Evalúa todas las reglas y persiste el resultado. Devuelve las alertas vigentes. */
    public function evaluar(): array
    {
        $this->detectadas = [];

        $this->reglaBasesEnNoArchivelog();
        $this->reglaBasesSinChequeo();
        $this->reglaEstrategiaSinProgramacion();
        $this->reglaEstrategiaSinScriptAprobado();
        $this->reglaEstrategiaInactivaEnProduccion();
        $this->reglaEjecucionFallidaReciente();
        $this->reglaRespaldoProgramadoNoEjecutado();
        $this->reglaSinRespaldoReciente();
        $this->reglaBaseSinEstrategia();
        $this->reglaArchivelogsNoIncluidos();

        $this->persistir();
        return $this->vigentes();
    }

    // =================== Reglas =====================================

    private function reglaBasesEnNoArchivelog(): void
    {
        $filas = $this->pdo->query("
            SELECT id, nombre FROM bases_datos
             WHERE activo = 1 AND modo_archivado = 'NOARCHIVELOG'
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('NOARCHIVELOG', 'advertencia',
                'La base "' . $f['nombre'] . '" está en modo NOARCHIVELOG: no admite recuperación ' .
                'hasta un punto en el tiempo. Revisá si la estrategia cubre ese riesgo.',
                null, (int) $f['id']);
        }
    }

    private function reglaBasesSinChequeo(): void
    {
        $filas = $this->pdo->query("
            SELECT id, nombre FROM bases_datos
             WHERE activo = 1
               AND (ultimo_chequeo IS NULL OR ultimo_chequeo < DATE_SUB(NOW(), INTERVAL 7 DAY))
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('SIN_CHEQUEO', 'informacion',
                'Hace más de una semana que no se verifica la conexión ni el modo de archivado de "' .
                $f['nombre'] . '".',
                null, (int) $f['id']);
        }
    }

    private function reglaEstrategiaSinProgramacion(): void
    {
        $filas = $this->pdo->query("
            SELECT id, nombre FROM estrategias
             WHERE estado = 'activa' AND (hora IS NULL OR fecha_inicio IS NULL OR proxima_ejecucion IS NULL)
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('SIN_PROGRAMACION', 'advertencia',
                'La estrategia "' . $f['nombre'] . '" está activa pero no tiene una próxima ' .
                'ejecución calculable. Nunca va a correr sola.',
                (int) $f['id']);
        }
    }

    private function reglaEstrategiaSinScriptAprobado(): void
    {
        $filas = $this->pdo->query("
            SELECT id, nombre, script_rman IS NULL AS sin_script FROM estrategias
             WHERE estado = 'activa' AND aprobado = 0
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('SIN_APROBACION', 'advertencia',
                'La estrategia "' . $f['nombre'] . '" está activa pero su script ' .
                ((int) $f['sin_script'] === 1 ? 'todavía no se ha generado.' : 'no ha sido aprobado.') .
                ' No se ejecutará hasta que un administrador lo revise.',
                (int) $f['id']);
        }
    }

    private function reglaEstrategiaInactivaEnProduccion(): void
    {
        $filas = $this->pdo->query("
            SELECT e.id, e.nombre, b.nombre AS base
              FROM estrategias e JOIN bases_datos b ON b.id = e.base_datos_id
             WHERE e.estado = 'inactiva' AND b.ambiente = 'produccion' AND b.activo = 1
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('INACTIVA_PRODUCCION', 'advertencia',
                'La estrategia "' . $f['nombre'] . '" sobre la base de producción "' . $f['base'] .
                '" está inactiva.',
                (int) $f['id']);
        }
    }

    private function reglaEjecucionFallidaReciente(): void
    {
        $filas = $this->pdo->query("
            SELECT ej.estrategia_id, e.nombre, MAX(ej.inicio) AS ultima
              FROM ejecuciones ej JOIN estrategias e ON e.id = ej.estrategia_id
             WHERE ej.resultado = 'fallido' AND ej.inicio >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY ej.estrategia_id, e.nombre
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('EJECUCION_FALLIDA', 'critica',
                'La estrategia "' . $f['nombre'] . '" falló el ' . $f['ultima'] .
                '. Revisá la salida de RMAN en el historial.',
                (int) $f['estrategia_id']);
        }
    }

    private function reglaRespaldoProgramadoNoEjecutado(): void
    {
        // Programada para hace más de 2 horas y sin ninguna ejecución posterior
        // a esa hora: el respaldo se saltó.
        $filas = $this->pdo->query("
            SELECT e.id, e.nombre, e.proxima_ejecucion
              FROM estrategias e
             WHERE e.estado = 'activa'
               AND e.proxima_ejecucion IS NOT NULL
               AND e.proxima_ejecucion < DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('NO_EJECUTADO', 'critica',
                'La estrategia "' . $f['nombre'] . '" estaba programada para ' . $f['proxima_ejecucion'] .
                ' y no se ejecutó. Verificá que el runner esté corriendo.',
                (int) $f['id']);
        }
    }

    private function reglaSinRespaldoReciente(): void
    {
        // Estrategias de prioridad alta sin respaldo exitoso en 48 horas.
        $filas = $this->pdo->query("
            SELECT e.id, e.nombre, e.ultima_ejecucion
              FROM estrategias e
             WHERE e.estado = 'activa' AND e.prioridad = 'alta'
               AND (e.ultima_ejecucion IS NULL OR e.ultima_ejecucion < DATE_SUB(NOW(), INTERVAL 48 HOUR))
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('SIN_RESPALDO_RECIENTE', 'critica',
                'La estrategia de prioridad alta "' . $f['nombre'] . '" no registra un respaldo ' .
                ($f['ultima_ejecucion'] ? 'desde ' . $f['ultima_ejecucion'] . '.' : 'nunca ejecutado.'),
                (int) $f['id']);
        }
    }

    private function reglaBaseSinEstrategia(): void
    {
        $filas = $this->pdo->query("
            SELECT b.id, b.nombre
              FROM bases_datos b
             WHERE b.activo = 1
               AND NOT EXISTS (SELECT 1 FROM estrategias e WHERE e.base_datos_id = b.id)
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('SIN_ESTRATEGIA', 'advertencia',
                'La base "' . $f['nombre'] . '" está registrada pero no tiene ninguna estrategia de respaldo.',
                null, (int) $f['id']);
        }
    }

    private function reglaArchivelogsNoIncluidos(): void
    {
        $filas = $this->pdo->query("
            SELECT e.id, e.nombre
              FROM estrategias e JOIN bases_datos b ON b.id = e.base_datos_id
             WHERE e.estado = 'activa' AND b.modo_archivado = 'ARCHIVELOG'
               AND e.incluir_archivelogs = 0
        ")->fetchAll();

        foreach ($filas as $f) {
            $this->agregar('ARCHIVELOGS_FUERA', 'recomendacion',
                'La base de "' . $f['nombre'] . '" está en ARCHIVELOG. Considere incorporar el respaldo ' .
                'periódico de los archived redo logs para mejorar las posibilidades de recuperación.',
                (int) $f['id']);
        }
    }

    // =================== Persistencia ===============================

    private function agregar(string $codigo, string $severidad, string $mensaje,
                             ?int $estrategiaId = null, ?int $baseDatosId = null): void
    {
        $this->detectadas[] = [
            'codigo' => $codigo, 'severidad' => $severidad, 'mensaje' => $mensaje,
            'estrategia_id' => $estrategiaId, 'base_datos_id' => $baseDatosId,
        ];
    }

    /**
     * Reemplaza las alertas no atendidas por el estado actual. Las alertas
     * marcadas como atendidas se conservan como historial.
     */
    private function persistir(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM alertas WHERE atendida = 0");

            $ins = $this->pdo->prepare("
                INSERT INTO alertas (codigo, severidad, mensaje, estrategia_id, base_datos_id, detectada_en)
                VALUES (:c, :s, :m, :e, :b, NOW())
            ");
            foreach ($this->detectadas as $a) {
                $ins->execute([
                    'c' => $a['codigo'], 's' => $a['severidad'], 'm' => $a['mensaje'],
                    'e' => $a['estrategia_id'], 'b' => $a['base_datos_id'],
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function vigentes(): array
    {
        return $this->pdo->query("
            SELECT a.*, e.nombre AS estrategia_nombre, b.nombre AS base_nombre
              FROM alertas a
              LEFT JOIN estrategias e ON e.id = a.estrategia_id
              LEFT JOIN bases_datos b ON b.id = a.base_datos_id
             WHERE a.atendida = 0
             ORDER BY FIELD(a.severidad,'critica','advertencia','recomendacion','informacion'), a.detectada_en DESC
        ")->fetchAll();
    }

    public function marcarAtendida(int $id, string $usuario): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE alertas SET atendida = 1, atendida_en = NOW(), atendida_por = :u WHERE id = :id
        ");
        $stmt->execute(['u' => $usuario, 'id' => $id]);
    }

    /** Conteo por severidad, para el tablero. */
    public function conteo(): array
    {
        $filas = $this->pdo->query("
            SELECT severidad, COUNT(*) AS total FROM alertas WHERE atendida = 0 GROUP BY severidad
        ")->fetchAll();

        $c = ['critica' => 0, 'advertencia' => 0, 'recomendacion' => 0, 'informacion' => 0];
        foreach ($filas as $f) {
            $c[$f['severidad']] = (int) $f['total'];
        }
        return $c;
    }
}

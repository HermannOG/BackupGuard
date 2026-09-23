<?php
/**
 * Acceso a datos de estrategias, bases registradas y evidencia.
 * Toda consulta usa sentencias preparadas.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Programacion.php';
require_once __DIR__ . '/RmanBuilder.php';

class EstrategiaRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    // ----------------------- Bases de datos -------------------------

    public function listarBases(bool $soloActivas = false): array
    {
        $sql = "SELECT * FROM bases_datos" . ($soloActivas ? " WHERE activo = 1" : "") . " ORDER BY nombre";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function obtenerBase(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM bases_datos WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function guardarBase(array $d): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO bases_datos
                (nombre, descripcion, ambiente, tns_alias, host, puerto, service_name,
                 usuario, password_enc, conectar_as_sysdba, activo)
            VALUES
                (:nombre, :descripcion, :ambiente, :tns_alias, :host, :puerto, :service_name,
                 :usuario, :password_enc, :sysdba, 1)
        ");
        $stmt->execute([
            'nombre'       => $d['nombre'],
            'descripcion'  => $d['descripcion'] ?: null,
            'ambiente'     => $d['ambiente'],
            'tns_alias'    => $d['tns_alias'] ?: null,
            'host'         => $d['host'] ?: null,
            'puerto'       => $d['puerto'] ?: null,
            'service_name' => $d['service_name'] ?: null,
            'usuario'      => $d['usuario'],
            'password_enc' => $d['password_enc'],
            'sysdba'       => $d['conectar_as_sysdba'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function eliminarBase(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE bases_datos SET activo = 0 WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ------------------------- Estrategias --------------------------

    public function listar(?string $estado = null): array
    {
        $sql = "SELECT e.*, b.nombre AS base_nombre, b.ambiente, b.modo_archivado
                  FROM estrategias e
                  JOIN bases_datos b ON b.id = e.base_datos_id";
        $params = [];
        if ($estado) {
            $sql .= " WHERE e.estado = :estado";
            $params['estado'] = $estado;
        }
        $sql .= " ORDER BY FIELD(e.prioridad,'alta','media','baja'), e.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*, b.nombre AS base_nombre, b.ambiente, b.modo_archivado
              FROM estrategias e
              JOIN bases_datos b ON b.id = e.base_datos_id
             WHERE e.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function objetos(int $estrategiaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM estrategia_objetos WHERE estrategia_id = :id ORDER BY nombre"
        );
        $stmt->execute(['id' => $estrategiaId]);
        return $stmt->fetchAll();
    }

    /** Inserta o actualiza. Devuelve el id de la estrategia. */
    public function guardar(array $d, ?int $id = null): int
    {
        $campos = [
            'nombre', 'descripcion', 'base_datos_id', 'responsable', 'prioridad',
            'justificacion_prioridad', 'estado', 'alcance', 'incluir_controlfile',
            'incluir_spfile', 'incluir_archivelogs', 'borrar_archivelogs',
            'tipo_respaldo', 'modalidad', 'comprimido', 'paralelismo',
            'retencion_dias', 'verificar_respaldo', 'fecha_inicio', 'hora',
            'frecuencia', 'dias_semana', 'dia_mes', 'ventana_minutos', 'destino',
        ];

        $params = [];
        foreach ($campos as $c) {
            $params[$c] = $d[$c] ?? null;
        }

        if ($id === null) {
            $params['creado_por'] = $_SESSION['usuario']['nombre_usuario'] ?? null;
            $cols = implode(', ', array_merge($campos, ['creado_por']));
            $vals = ':' . implode(', :', array_merge($campos, ['creado_por']));
            $stmt = $this->pdo->prepare("INSERT INTO estrategias ($cols) VALUES ($vals)");
            $stmt->execute($params);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $sets = implode(', ', array_map(fn($c) => "$c = :$c", $campos));
            // Editar una estrategia invalida la aprobación anterior: el script
            // cambia, así que debe volver a revisarse.
            $params['id'] = $id;
            $stmt = $this->pdo->prepare(
                "UPDATE estrategias SET $sets, aprobado = 0, aprobado_por = NULL,
                        aprobado_en = NULL, script_rman = NULL
                 WHERE id = :id"
            );
            $stmt->execute($params);
        }

        // Objetos (tablespaces / datafiles)
        $del = $this->pdo->prepare("DELETE FROM estrategia_objetos WHERE estrategia_id = :id");
        $del->execute(['id' => $id]);

        if (!empty($d['objetos']) && $d['alcance'] !== 'base_completa') {
            $tipo = $d['alcance'] === 'tablespaces' ? 'tablespace' : 'datafile';
            $ins = $this->pdo->prepare(
                "INSERT INTO estrategia_objetos (estrategia_id, tipo, nombre) VALUES (:e, :t, :n)"
            );
            foreach ($d['objetos'] as $nombre) {
                $nombre = trim((string) $nombre);
                if ($nombre === '') { continue; }
                $ins->execute(['e' => $id, 't' => $tipo, 'n' => $nombre]);
            }
        }

        $this->recalcularProxima($id);
        return $id;
    }

    public function eliminar(int $id): void
    {
        $this->pdo->prepare("DELETE FROM alertas WHERE estrategia_id = :id")->execute(['id' => $id]);
        $this->pdo->prepare("DELETE FROM ejecuciones WHERE estrategia_id = :id")->execute(['id' => $id]);
        $this->pdo->prepare("DELETE FROM estrategias WHERE id = :id")->execute(['id' => $id]);
    }

    public function cambiarEstado(int $id, string $estado): void
    {
        $stmt = $this->pdo->prepare("UPDATE estrategias SET estado = :e WHERE id = :id");
        $stmt->execute(['e' => $estado, 'id' => $id]);
        $this->recalcularProxima($id);
    }

    // --------------------- Script y aprobación ----------------------

    /** Genera el script RMAN y lo guarda (sin aprobarlo). */
    public function generarScript(int $id): string
    {
        $e = $this->obtener($id);
        if (!$e) { throw new RuntimeException('Estrategia no encontrada.'); }

        $bd = $this->obtenerBase((int) $e['base_datos_id']);
        $builder = new RmanBuilder($e, $bd, $this->objetos($id));
        $script = $builder->construir();

        $stmt = $this->pdo->prepare("
            UPDATE estrategias
               SET script_rman = :s, script_generado_en = NOW(),
                   aprobado = 0, aprobado_por = NULL, aprobado_en = NULL
             WHERE id = :id
        ");
        $stmt->execute(['s' => $script, 'id' => $id]);

        return $script;
    }

    public function aprobar(int $id, string $usuario): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE estrategias SET aprobado = 1, aprobado_por = :u, aprobado_en = NOW()
             WHERE id = :id AND script_rman IS NOT NULL
        ");
        $stmt->execute(['u' => $usuario, 'id' => $id]);
    }

    public function recalcularProxima(int $id): void
    {
        $e = $this->obtener($id);
        if (!$e) { return; }

        $proxima = $e['estado'] === 'activa' ? Programacion::proxima($e) : null;

        $stmt = $this->pdo->prepare("UPDATE estrategias SET proxima_ejecucion = :p WHERE id = :id");
        $stmt->execute([
            'p'  => $proxima ? $proxima->format('Y-m-d H:i:s') : null,
            'id' => $id,
        ]);
    }

    /** Estrategias activas, aprobadas y cuya próxima ejecución ya venció. */
    public function pendientesDeEjecutar(): array
    {
        return $this->pdo->query("
            SELECT e.*, b.nombre AS base_nombre, b.ambiente, b.modo_archivado
              FROM estrategias e
              JOIN bases_datos b ON b.id = e.base_datos_id
             WHERE e.estado = 'activa'
               AND e.aprobado = 1
               AND e.proxima_ejecucion IS NOT NULL
               AND e.proxima_ejecucion <= NOW()
             ORDER BY e.proxima_ejecucion
        ")->fetchAll();
    }

    // ---------------------- Evidencia / historial -------------------

    public function historial(?int $estrategiaId = null, int $limite = 50): array
    {
        $sql = "SELECT ej.*, e.nombre AS estrategia_nombre, b.nombre AS base_nombre
                  FROM ejecuciones ej
                  JOIN estrategias e ON e.id = ej.estrategia_id
                  JOIN bases_datos b ON b.id = ej.base_datos_id";
        $params = [];
        if ($estrategiaId) {
            $sql .= " WHERE ej.estrategia_id = :id";
            $params['id'] = $estrategiaId;
        }
        $sql .= " ORDER BY ej.inicio DESC LIMIT " . (int) $limite;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function obtenerEjecucion(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ej.*, e.nombre AS estrategia_nombre, b.nombre AS base_nombre
              FROM ejecuciones ej
              JOIN estrategias e ON e.id = ej.estrategia_id
              JOIN bases_datos b ON b.id = ej.base_datos_id
             WHERE ej.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Resumen para el tablero: conteos por resultado en los últimos N días. */
    public function resumen(int $dias = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT resultado, COUNT(*) AS total
              FROM ejecuciones
             WHERE inicio >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY resultado
        ");
        $stmt->execute(['d' => $dias]);

        $resumen = ['exitoso' => 0, 'advertencia' => 0, 'fallido' => 0, 'en_curso' => 0];
        foreach ($stmt->fetchAll() as $f) {
            $resumen[$f['resultado']] = (int) $f['total'];
        }
        return $resumen;
    }
}

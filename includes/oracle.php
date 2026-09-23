<?php
/**
 * Conexión a las bases Oracle registradas (OCI8) y consultas de contexto.
 *
 * BackupGuard consulta Oracle solo para LEER contexto: modo de archivado,
 * tablespaces, datafiles y espacio de la Fast Recovery Area. Nunca cambia
 * la configuración de la base — el enunciado es explícito en que el modo de
 * archivado lo decide el administrador, no la herramienta.
 *
 * Requiere la extensión oci8 + Oracle Instant Client. Si no están, las
 * funciones lanzan RuntimeException y la interfaz cae al modo manual.
 */

require_once __DIR__ . '/db.php';  // fija la zona horaria configurada
require_once __DIR__ . '/crypto.php';

function oracleDisponible(): bool
{
    return extension_loaded('oci8');
}

/**
 * Abre una conexión OCI8 a una base registrada.
 * @param array $bd fila de bases_datos
 * @return resource
 */
function conectarOracle(array $bd)
{
    if (!oracleDisponible()) {
        throw new RuntimeException(
            'La extensión oci8 de PHP no está habilitada. Habilitala en php.ini ' .
            'o trabajá en modo simulación.'
        );
    }

    $c = config();
    if (!empty($c['oracle_tns_admin'])) {
        putenv('TNS_ADMIN=' . rtrim($c['oracle_tns_admin'], '/\\'));
    }

    $connString = !empty($bd['tns_alias'])
        ? $bd['tns_alias']
        : sprintf('%s:%s/%s', $bd['host'], $bd['puerto'], $bd['service_name']);

    $password = descifrar($bd['password_enc']);
    $modo = ((int) ($bd['conectar_as_sysdba'] ?? 1) === 1) ? OCI_SYSDBA : OCI_DEFAULT;

    $conn = @oci_connect($bd['usuario'], $password, $connString, 'AL32UTF8', $modo);

    if (!$conn) {
        $err = oci_error();
        throw new RuntimeException('No se pudo conectar a Oracle: ' . ($err['message'] ?? 'error desconocido'));
    }

    return $conn;
}

/** Ejecuta una consulta y devuelve todas las filas como arreglos asociativos. */
function oracleConsultar($conn, string $sql, array $binds = []): array
{
    $stmt = oci_parse($conn, $sql);
    foreach ($binds as $nombre => $valor) {
        oci_bind_by_name($stmt, $nombre, $binds[$nombre]);
    }
    if (!@oci_execute($stmt)) {
        $err = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException('Error en consulta Oracle: ' . ($err['message'] ?? '?'));
    }
    $filas = [];
    while ($fila = oci_fetch_assoc($stmt)) {
        $filas[] = $fila;
    }
    oci_free_statement($stmt);
    return $filas;
}

/**
 * Lee el contexto completo de una base: modo de archivado, nombre, tablespaces,
 * datafiles y espacio de la FRA. Es lo que alimenta las advertencias y
 * recomendaciones de la interfaz.
 */
function leerContextoOracle(array $bd): array
{
    $conn = conectarOracle($bd);

    $ctx = ['modo_archivado' => 'DESCONOCIDO'];

    $filas = oracleConsultar($conn, "SELECT name, log_mode, open_mode, dbid FROM v\$database");
    if ($filas) {
        $ctx['nombre_bd']  = $filas[0]['NAME'] ?? null;
        $ctx['modo_archivado'] = strtoupper(trim($filas[0]['LOG_MODE'] ?? 'DESCONOCIDO'));
        $ctx['open_mode']  = $filas[0]['OPEN_MODE'] ?? null;
        $ctx['dbid']       = $filas[0]['DBID'] ?? null;
    }

    $ctx['tablespaces'] = array_map(
        fn($f) => $f['TABLESPACE_NAME'],
        oracleConsultar($conn, "SELECT tablespace_name FROM dba_tablespaces ORDER BY tablespace_name")
    );

    $ctx['datafiles'] = oracleConsultar(
        $conn,
        "SELECT file_id, file_name, tablespace_name, bytes FROM dba_data_files ORDER BY file_id"
    );

    // Espacio de la Fast Recovery Area: base de la alerta de "falta de espacio".
    try {
        $fra = oracleConsultar($conn, "
            SELECT name,
                   space_limit,
                   space_used,
                   ROUND(space_used / NULLIF(space_limit,0) * 100, 1) AS pct_usado
              FROM v\$recovery_file_dest
        ");
        $ctx['fra'] = $fra[0] ?? null;
    } catch (Throwable $e) {
        $ctx['fra'] = null;
    }

    // Último respaldo conocido según el propio catálogo de RMAN.
    try {
        $ultimo = oracleConsultar($conn, "
            SELECT TO_CHAR(MAX(completion_time), 'YYYY-MM-DD HH24:MI:SS') AS ultimo
              FROM v\$backup_set
        ");
        $ctx['ultimo_backup_rman'] = $ultimo[0]['ULTIMO'] ?? null;
    } catch (Throwable $e) {
        $ctx['ultimo_backup_rman'] = null;
    }

    oci_close($conn);
    return $ctx;
}

/**
 * Comprueba la conexión y actualiza modo_archivado en bases_datos.
 * Devuelve el contexto leído.
 */
function refrescarModoArchivado(int $baseDatosId): array
{
    $stmt = db()->prepare("SELECT * FROM bases_datos WHERE id = :id");
    $stmt->execute(['id' => $baseDatosId]);
    $bd = $stmt->fetch();
    if (!$bd) {
        throw new RuntimeException('La base de datos indicada no existe.');
    }

    $ctx = leerContextoOracle($bd);

    $upd = db()->prepare(
        "UPDATE bases_datos SET modo_archivado = :m, ultimo_chequeo = NOW() WHERE id = :id"
    );
    $upd->execute(['m' => $ctx['modo_archivado'], 'id' => $baseDatosId]);

    bitacora('chequeo_base', 'bases_datos', $baseDatosId,
        'Modo de archivado detectado: ' . $ctx['modo_archivado']);

    return $ctx;
}

/** Cadena de conexión que se le pasa a rman como TARGET. */
function cadenaTargetRman(array $bd): string
{
    $password = descifrar($bd['password_enc']);
    $destino = !empty($bd['tns_alias'])
        ? $bd['tns_alias']
        : sprintf('%s:%s/%s', $bd['host'], $bd['puerto'], $bd['service_name']);

    $cadena = sprintf('%s/%s@%s', $bd['usuario'], $password, $destino);
    if ((int) ($bd['conectar_as_sysdba'] ?? 1) === 1) {
        $cadena .= ' AS SYSDBA';
    }
    return $cadena;
}

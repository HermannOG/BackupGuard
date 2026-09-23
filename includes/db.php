<?php
/**
 * Conexión PDO a la base de datos propia de BackupGuard.
 * Las credenciales viven en includes/config.php (fuera de git).
 */

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException(
            'Falta includes/config.php. Copia includes/config.example.php, ' .
            'renómbralo y coloca tus credenciales reales.'
        );
    }

    $config = require $configPath;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $c = config();

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $c['host'], $c['port'], $c['dbname']
    );

    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // La sesión de MySQL usa el MISMO desfase que PHP. Si no coincidieran,
    // proxima_ejecucion se calcularía con un reloj y se compararía contra
    // NOW() con otro, y el runner ejecutaría a destiempo.
    $pdo->exec("SET time_zone = '" . desfaseHorario() . "'");

    return $pdo;
}

/**
 * Fija la zona horaria de PHP una sola vez, tomándola de la configuración.
 * Se llama desde cualquier punto de entrada (web o línea de comandos).
 */
function zonaHoraria(): string
{
    static $zona = null;
    if ($zona === null) {
        $zona = config()['zona_horaria'] ?? 'America/Costa_Rica';
        date_default_timezone_set($zona);
    }
    return $zona;
}

/** Desfase actual ('-06:00') de la zona configurada, para la sesión de MySQL. */
function desfaseHorario(): string
{
    $offset = (new DateTimeZone(zonaHoraria()))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    $signo = $offset < 0 ? '-' : '+';
    $offset = abs($offset);
    return sprintf('%s%02d:%02d', $signo, intdiv($offset, 3600), intdiv($offset % 3600, 60));
}

zonaHoraria();

/**
 * BackupGuard es una herramienta local: guarda credenciales con privilegio
 * SYSDBA y ejecuta RMAN contra la base. Exponerla en una red sería un riesgo
 * mayor que el que pretende controlar, así que por omisión solo responde a
 * peticiones desde la misma máquina.
 *
 * Si necesitás llegar desde otro equipo de la red del laboratorio, poné
 * 'solo_local' => false en includes/config.php — bajo tu responsabilidad.
 */
function exigirAccesoLocal(): void
{
    if (PHP_SAPI === 'cli') {
        return;  // el runner corre desde la línea de comandos
    }

    $c = config();
    if (($c['solo_local'] ?? true) === false) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $locales = ['127.0.0.1', '::1', 'localhost', ''];

    if (!in_array($ip, $locales, true)) {
        http_response_code(403);
        die(
            'BackupGuard solo acepta conexiones desde esta misma computadora. ' .
            'Abrilo en http://localhost:8080 desde el equipo donde está instalado.'
        );
    }
}

exigirAccesoLocal();

/** Registra una acción en la bitácora de auditoría. */
function bitacora(string $accion, ?string $entidad = null, ?int $entidadId = null, ?string $detalle = null): void
{
    $usuario = $_SESSION['usuario']['nombre_usuario'] ?? null;
    $stmt = db()->prepare(
        "INSERT INTO bitacora (usuario, accion, entidad, entidad_id, detalle, ocurrido_en)
         VALUES (:u, :a, :e, :ei, :d, NOW())"
    );
    $stmt->execute([
        'u' => $usuario, 'a' => $accion, 'e' => $entidad,
        'ei' => $entidadId, 'd' => $detalle,
    ]);
}

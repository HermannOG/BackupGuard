<?php
/**
 * Detección de columnas opcionales del esquema.
 *
 * Las funciones de intervalo y dispositivo (sección 7 del enunciado) usan
 * columnas que agrega database/migracion-intervalo-dispositivo.sql. Si una
 * instalación todavía no aplicó la migración, la aplicación sigue funcionando
 * igual que antes: simplemente no muestra ni guarda esos campos.
 */
require_once __DIR__ . '/db.php';

function columnaExiste(string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = $tabla . '.' . $columna;
    if (!array_key_exists($clave, $cache)) {
        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
            );
            $stmt->execute(['t' => $tabla, 'c' => $columna]);
            $cache[$clave] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $ex) {
            $cache[$clave] = false;
        }
    }
    return $cache[$clave];
}

/** ¿La base tiene el intervalo de repetición y la frecuencia "por horas"? */
function soportaIntervalo(): bool
{
    return columnaExiste('estrategias', 'intervalo');
}

/** ¿La base tiene el tipo e identificación del dispositivo de destino? */
function soportaDispositivo(): bool
{
    return columnaExiste('estrategias', 'dispositivo') && columnaExiste('estrategias', 'dispositivo_id');
}

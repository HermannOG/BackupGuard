<?php
/**
 * Cifrado de las contraseñas de las bases Oracle registradas.
 *
 * No se usa password_hash() porque la herramienta NECESITA recuperar la
 * contraseña en claro para conectarse con RMAN; se usa cifrado simétrico
 * AES-256-GCM con la clave de includes/config.php.
 */

require_once __DIR__ . '/db.php';

function claveCifrado(): string
{
    $c = config();
    $frase = $c['encryption_key'] ?? '';
    if ($frase === '' || $frase === 'cambia-esto-por-una-frase-larga-solo-tuya') {
        throw new RuntimeException(
            'Definí una encryption_key propia en includes/config.php antes de guardar contraseñas.'
        );
    }
    return hash('sha256', $frase, true);
}

function cifrar(string $texto): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($texto, 'aes-256-gcm', claveCifrado(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifrado === false) {
        throw new RuntimeException('No se pudo cifrar la contraseña.');
    }
    return $iv . $tag . $cifrado;
}

function descifrar(string $blob): string
{
    $iv      = substr($blob, 0, 12);
    $tag     = substr($blob, 12, 16);
    $cifrado = substr($blob, 28);
    $texto = openssl_decrypt($cifrado, 'aes-256-gcm', claveCifrado(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($texto === false) {
        throw new RuntimeException(
            'No se pudo descifrar la contraseña: la encryption_key cambió o el dato está dañado.'
        );
    }
    return $texto;
}

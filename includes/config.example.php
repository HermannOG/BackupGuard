<?php
/**
 * Copia este archivo como includes/config.php y coloca tus datos reales.
 * config.php NO se sube a git (ver .gitignore).
 *
 * BackupGuard corre de forma local, en la misma máquina donde está Oracle
 * (o donde está instalado el cliente de Oracle con acceso a la base).
 */
return [
    // --- Base de datos propia de BackupGuard (MySQL/MariaDB local) ---
    // Con XAMPP por omisión: usuario root y contraseña vacía.
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'dbname'   => 'backupguard',
    'user'     => 'root',
    'password' => '',

    // Clave para cifrar las contraseñas de las bases Oracle registradas.
    // Cámbiala por una frase larga y única ANTES de registrar nada.
    'encryption_key' => 'cambia-esto-por-una-frase-larga-solo-tuya',

    // Solo acepta peticiones desde esta misma computadora.
    // Ponelo en false únicamente si necesitás entrar desde otro equipo
    // de la red del laboratorio.
    'solo_local' => true,

    // --- Entorno Oracle ---
    // Ruta al ejecutable rman.
    //   Windows XE 21c: 'C:\\app\\TU_USUARIO\\product\\21c\\dbhomeXE\\bin\\rman.exe'
    //   Linux:          '/opt/oracle/product/21c/dbhomeXE/bin/rman'
    // Si rman ya está en el PATH del sistema, basta con dejar 'rman'.
    'rman_bin' => 'rman',

    // Carpeta que contiene tnsnames.ora. null = intentar TNS_ADMIN / ORACLE_HOME.
    //   Windows XE 21c: 'C:\\app\\TU_USUARIO\\product\\21c\\homes\\OraDB21Home1\\network\\admin'
    'oracle_tns_admin' => null,

    // Carpeta de trabajo para los cmdfile y logs que genera la herramienta.
    'ruta_trabajo' => __DIR__ . '/../storage',

    // MODO SIMULACIÓN
    // true  -> no se invoca rman; la ejecución se simula y se marca como tal.
    //          Dejalo así mientras no tengas Oracle y oci8 listos: toda la
    //          herramienta funciona igual. También sirve para demostrar un
    //          fallo controlado sin tocar ninguna base.
    // false -> se ejecuta rman de verdad contra la base registrada.
    'modo_simulacion' => true,

    // Segundos máximos que se espera por una ejecución de rman.
    'timeout_rman' => 3600,

    // Zona horaria con la que se programa y se registra todo.
    // PHP y MySQL usan esta misma, para que la programación no se corra.
    'zona_horaria' => 'America/Costa_Rica',
];

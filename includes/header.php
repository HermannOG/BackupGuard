<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/ui.php';
$titulo = $tituloPagina ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $titulo ? e($titulo) . ' · BackupGuard' : 'BackupGuard — Gestión de estrategias de respaldo Oracle' ?></title>
<meta name="description" content="Herramienta para definir, generar, automatizar y verificar estrategias de respaldo de bases de datos Oracle con RMAN.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('assets/css/estilos.css') ?>">
</head>
<body>

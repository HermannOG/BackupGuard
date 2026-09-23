<?php
/** Helpers de presentación compartidos por todas las vistas. */

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Insignia de resultado de una ejecución. */
function insigniaResultado(string $resultado): string
{
    $mapa = [
        'exitoso'     => ['ok',     'Exitoso'],
        'advertencia' => ['warn',   'Con advertencias'],
        'fallido'     => ['bad',    'Fallido'],
        'en_curso'    => ['info',   'En curso'],
    ];
    [$clase, $texto] = $mapa[$resultado] ?? ['neutra', $resultado];
    return '<span class="insignia ' . $clase . '">' . e($texto) . '</span>';
}

function insigniaPrioridad(string $prioridad): string
{
    return '<span class="insignia ' . e($prioridad) . '">' . e(strtoupper($prioridad)) . '</span>';
}

function insigniaEstado(string $estado): string
{
    $clase = $estado === 'activa' ? 'ok' : 'neutra';
    return '<span class="insignia ' . $clase . '">' . e(ucfirst($estado)) . '</span>';
}

function insigniaArchivado(string $modo): string
{
    $clase = match ($modo) {
        'ARCHIVELOG'   => 'ok',
        'NOARCHIVELOG' => 'warn',
        default        => 'neutra',
    };
    return '<span class="insignia ' . $clase . '">' . e($modo) . '</span>';
}

function insigniaSeveridad(string $severidad): string
{
    $mapa = [
        'critica'      => ['bad',  'Crítica'],
        'advertencia'  => ['warn', 'Advertencia'],
        'recomendacion'=> ['ok',   'Recomendación'],
        'informacion'  => ['info', 'Información'],
    ];
    [$clase, $texto] = $mapa[$severidad] ?? ['neutra', $severidad];
    return '<span class="insignia ' . $clase . '">' . e($texto) . '</span>';
}

/** Caja de aviso (error / advertencia / recomendación / información / éxito). */
function aviso(string $nivel, string $mensaje, ?string $titulo = null): string
{
    $html = '<div class="aviso ' . e($nivel) . '">';
    if ($titulo) {
        $html .= '<span class="titulo">' . e($titulo) . '</span>';
    }
    $html .= '<p>' . e($mensaje) . '</p></div>';
    return $html;
}

function formatoDuracion(?int $segundos): string
{
    if ($segundos === null) { return '—'; }
    if ($segundos < 60) { return $segundos . ' s'; }
    $m = intdiv($segundos, 60);
    $s = $segundos % 60;
    if ($m < 60) { return sprintf('%d m %02d s', $m, $s); }
    return sprintf('%d h %02d m', intdiv($m, 60), $m % 60);
}

function formatoBytes(?int $bytes): string
{
    if ($bytes === null) { return '—'; }
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
    return round($v, $i === 0 ? 0 : 1) . ' ' . $u[$i];
}

function formatoFecha(?string $fecha): string
{
    if (!$fecha) { return '—'; }
    return date('d/m/Y H:i', strtotime($fecha));
}

/** Token CSRF para los formularios que cambian datos. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrfCampo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function verificarCsrf(): void
{
    $enviado = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $enviado)) {
        http_response_code(400);
        die('Token de seguridad inválido. Volvé a cargar la página e intentá de nuevo.');
    }
}

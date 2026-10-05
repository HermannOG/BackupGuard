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

/** Tipo de respaldo en lenguaje claro: "incremental_0" → "Incremental nivel 0". */
function tipoRespaldoLegible(?string $tipo, ?string $modalidad = null): string
{
    return match ($tipo) {
        'completo'      => 'Completo',
        'incremental_0' => 'Incremental nivel 0',
        'incremental_1' => 'Incremental nivel 1' . ($modalidad ? ' ' . $modalidad : ''),
        default         => ucfirst(str_replace('_', ' ', (string) $tipo)),
    };
}

/** Insignia del origen de una ejecución (manual / programada). */
function insigniaOrigen(string $origen): string
{
    return $origen === 'programada'
        ? '<span class="insignia info">Automática</span>'
        : '<span class="insignia neutra">Manual</span>';
}

/**
 * Presentación de una alerta: título en lenguaje claro, riesgo que afecta y
 * hacia dónde ir para resolverla. Solo cambia cómo se muestra; el código de
 * la alerta y su lógica siguen viniendo de Alertas.php.
 * @return array{titulo:string, riesgo:string, accion:string}
 */
function presentacionAlerta(string $codigo): array
{
    $mapa = [
        'NOARCHIVELOG'          => ['Base en modo NOARCHIVELOG',            'Integridad',     'Revisar la base'],
        'SIN_CHEQUEO'           => ['Base sin verificar recientemente',     'Disponibilidad', 'Verificar la base'],
        'SIN_PROGRAMACION'      => ['Estrategia sin programación',          'Disponibilidad', 'Programar la estrategia'],
        'SIN_APROBACION'        => ['Script sin aprobar',                   'Disponibilidad', 'Revisar y aprobar'],
        'INACTIVA_PRODUCCION'   => ['Estrategia de producción inactiva',    'Disponibilidad', 'Activar la estrategia'],
        'EJECUCION_FALLIDA'     => ['Última ejecución fallida',             'Disponibilidad', 'Ver la evidencia'],
        'NO_EJECUTADO'          => ['Respaldo programado que no corrió',    'Disponibilidad', 'Revisar el ejecutor'],
        'SIN_RESPALDO_RECIENTE' => ['Sin respaldo reciente',                'Disponibilidad', 'Ejecutar un respaldo'],
        'SIN_ESTRATEGIA'        => ['Base sin ninguna estrategia',          'Disponibilidad', 'Crear una estrategia'],
        'ARCHIVELOGS_FUERA'     => ['Archived logs fuera de la estrategia', 'Integridad',     'Incluir archived logs'],
        'ESPACIO_DESTINO'       => ['Poco espacio en el destino',           'Disponibilidad', 'Liberar espacio'],
    ];
    [$titulo, $riesgo, $accion] = $mapa[$codigo] ?? [ucfirst(strtolower(str_replace('_', ' ', $codigo))), 'Disponibilidad', 'Revisar'];
    return ['titulo' => $titulo, 'riesgo' => $riesgo, 'accion' => $accion];
}

/**
 * Título claro, riesgo que representa y qué hacer, para cada código de alerta.
 * Así la pantalla no muestra solo "SIN_CHEQUEO", sino qué significa.
 * @return array{0:string, 1:string[], 2:string} [título, riesgos, acción sugerida]
 */
function infoAlerta(string $codigo): array
{
    $mapa = [
        'NOARCHIVELOG'          => ['Base en modo NOARCHIVELOG', ['Integridad'],
                                    'Evaluá pasarla a ARCHIVELOG para poder recuperar hasta un punto en el tiempo.'],
        'SIN_CHEQUEO'           => ['Base sin verificar recientemente', ['Integridad'],
                                    'Presioná «Verificar» en Bases de datos para confirmar la conexión y el modo de archivado.'],
        'SIN_PROGRAMACION'      => ['Estrategia sin programación', ['Disponibilidad'],
                                    'Editá la estrategia y definí fecha, hora y frecuencia.'],
        'SIN_APROBACION'        => ['Script sin aprobar', ['Disponibilidad'],
                                    'Revisá el script RMAN de la estrategia y aprobalo para que pueda ejecutarse.'],
        'INACTIVA_PRODUCCION'   => ['Estrategia de producción inactiva', ['Disponibilidad'],
                                    'Activá la estrategia o confirmá que la base tiene otra protección.'],
        'EJECUCION_FALLIDA'     => ['Ejecución fallida', ['Disponibilidad', 'Integridad'],
                                    'Abrí la evidencia, corregí la causa y volvé a ejecutar.'],
        'NO_EJECUTADO'          => ['Respaldo programado que no corrió', ['Disponibilidad'],
                                    'Comprobá que el ejecutor automático (iniciar-ejecutor.bat) esté corriendo.'],
        'SIN_RESPALDO_RECIENTE' => ['Sin respaldo reciente', ['Disponibilidad', 'Integridad'],
                                    'Ejecutá la estrategia o revisá por qué no se ha ejecutado.'],
        'SIN_ESTRATEGIA'        => ['Base sin estrategia de respaldo', ['Disponibilidad'],
                                    'Creá una estrategia para esta base.'],
        'ARCHIVELOGS_FUERA'     => ['Archived logs fuera de la estrategia', ['Integridad'],
                                    'Incluí los archived redo logs en alguna estrategia de esta base.'],
        'ESPACIO_DESTINO'       => ['Poco espacio en el destino', ['Disponibilidad'],
                                    'Liberá espacio en el disco o cambiá el destino del respaldo.'],
    ];
    return $mapa[$codigo] ?? [ucfirst(strtolower(str_replace('_', ' ', $codigo))), [], ''];
}

/** Tarjeta de una alerta (página de Alertas y Tablero). */
function tarjetaAlerta(array $a, bool $compacta = false, bool $puedeAtender = false): string
{
    [$titulo, $riesgos, $accion] = infoAlerta($a['codigo']);
    $iconos = ['critica' => '!', 'advertencia' => '!', 'recomendacion' => '✓', 'informacion' => 'i'];

    $h  = '<article class="alerta alerta-' . e($a['severidad']) . ($compacta ? ' compacta' : '') . '">';
    $h .= '<span class="alerta-icono" aria-hidden="true">' . ($iconos[$a['severidad']] ?? 'i') . '</span>';
    $h .= '<div class="alerta-cuerpo">';
    $h .= '<div class="alerta-cabeza"><strong>' . e($titulo) . '</strong>' . insigniaSeveridad($a['severidad']);
    foreach ($riesgos as $r) {
        $h .= '<span class="riesgo">' . e($r) . '</span>';
    }
    $h .= '</div>';
    $h .= '<p>' . e($a['mensaje']) . '</p>';
    if (!$compacta && $accion !== '') {
        $h .= '<p class="alerta-accion"><b>Qué hacer:</b> ' . e($accion) . '</p>';
    }
    $h .= '<div class="alerta-pie"><span class="mono">' . e($a['codigo']) . '</span>';
    if (!empty($a['detectada_en'])) {
        $h .= '<span>Detectada ' . formatoFecha($a['detectada_en']) . '</span>';
    }
    $h .= '</div></div>';

    if (!$compacta) {
        $h .= '<div class="alerta-acciones">';
        if (!empty($a['estrategia_id'])) {
            $h .= '<a class="boton boton-chico" href="estrategia-detalle.php?id=' . (int) $a['estrategia_id'] . '">Ver estrategia</a>';
        } elseif (!empty($a['base_datos_id'])) {
            $h .= '<a class="boton boton-chico" href="bases-datos.php">Ver bases de datos</a>';
        }
        if ($puedeAtender) {
            $h .= '<form method="post">' . csrfCampo()
               .  '<input type="hidden" name="id" value="' . (int) $a['id'] . '">'
               .  '<button class="boton boton-chico boton-fantasma" type="submit">Marcar como atendida</button></form>';
        }
        $h .= '</div>';
    }
    $h .= '</article>';
    return $h;
}

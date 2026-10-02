<?php
/**
 * Programacion — el "CUÁNDO" de la estrategia.
 *
 * Calcula cuándo toca la siguiente ejecución a partir de fecha de inicio,
 * hora, frecuencia y días. El runner (scripts/runner.php) consulta esto para
 * decidir qué ejecutar; cron / Oracle Scheduler / Task Scheduler solo tienen
 * que invocar el runner cada pocos minutos.
 */
class Programacion
{
    public const DIAS = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    /**
     * Próxima fecha/hora de ejecución posterior a $desde.
     * Devuelve null si la estrategia no puede programarse.
     */
    public static function proxima(array $e, ?DateTimeImmutable $desde = null): ?DateTimeImmutable
    {
        if (empty($e['fecha_inicio']) || empty($e['hora'])) {
            return null;
        }

        $desde = $desde ?? new DateTimeImmutable('now');
        $hora  = substr((string) $e['hora'], 0, 8);

        $inicio = new DateTimeImmutable($e['fecha_inicio'] . ' ' . $hora);

        if ($e['frecuencia'] === 'unica') {
            return $inicio > $desde ? $inicio : null;
        }

        if ($e['frecuencia'] === 'semanal') {
            return self::proximaSemanal($e, $desde);
        }

        // Punto de partida: hoy a la hora indicada, nunca antes de fecha_inicio.
        $candidata = new DateTimeImmutable($desde->format('Y-m-d') . ' ' . $hora);
        if ($candidata < $inicio) {
            $candidata = $inicio;
        }

        // Se avanza día a día hasta encontrar uno que cumpla la regla.
        // 400 iteraciones cubren con holgura cualquier frecuencia mensual.
        for ($i = 0; $i < 400; $i++) {
            if ($candidata > $desde && self::aplicaEnDia($e, $candidata)) {
                return $candidata;
            }
            $candidata = $candidata->modify('+1 day');
        }

        return null;
    }

    /**
     * Semanal: recorre el catálogo día-hora (como el ejecutor de la pizarra:
     * ¿es el día? ¿es la hora?) y toma el primer par posterior a $desde.
     */
    private static function proximaSemanal(array $e, DateTimeImmutable $desde): ?DateTimeImmutable
    {
        $horarios = self::horarios($e);
        if (!$horarios) {
            return null;
        }

        $dia = new DateTimeImmutable($desde->format('Y-m-d'));
        $inicio = new DateTimeImmutable($e['fecha_inicio']);
        if ($dia < $inicio) {
            $dia = $inicio;
        }

        // 8 días: alcanza para volver al mismo día de la semana siguiente.
        for ($i = 0; $i < 8; $i++) {
            $n = (int) $dia->format('N');
            foreach ($horarios as [$d, $hora]) {   // vienen ordenados por día y hora
                $candidata = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $hora);
                if ($d === $n && $candidata > $desde) {
                    return $candidata;
                }
            }
            $dia = $dia->modify('+1 day');
        }

        return null;
    }

    /**
     * Pares [día, 'HH:MM:SS'] de una estrategia semanal, ordenados.
     * Sale de estrategia_horarios; las estrategias anteriores al catálogo
     * día-hora no tienen filas ahí y usan sus días con la hora general.
     *
     * @return array<int, array{0:int, 1:string}>
     */
    public static function horarios(array $e): array
    {
        if (!empty($e['horarios'])) {
            $pares = array_map(
                fn($h) => [(int) $h['dia_semana'], substr((string) $h['hora'], 0, 8)],
                $e['horarios']
            );
        } else {
            $hora = substr((string) ($e['hora'] ?? ''), 0, 8);
            $pares = $hora === '' ? [] : array_map(fn($d) => [$d, $hora], self::diasSemana($e));
        }

        usort($pares, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $pares;
    }

    private static function aplicaEnDia(array $e, DateTimeImmutable $fecha): bool
    {
        switch ($e['frecuencia']) {
            case 'diaria':
                return true;

            case 'mensual':
                $diaMes = (int) ($e['dia_mes'] ?? 0);
                if ($diaMes < 1) { return false; }
                $ultimoDelMes = (int) $fecha->format('t');
                // Si el mes no llega al día pedido (ej. 31 en febrero),
                // se ejecuta el último día del mes.
                $objetivo = min($diaMes, $ultimoDelMes);
                return (int) $fecha->format('j') === $objetivo;

            default:
                return false;
        }
    }

    /** @return int[] */
    public static function diasSemana(array $e): array
    {
        $raw = trim((string) ($e['dias_semana'] ?? ''));
        if ($raw === '') { return []; }
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /** Descripción legible de la programación, para la interfaz y la evidencia. */
    public static function describir(array $e): string
    {
        if (empty($e['hora'])) {
            return 'Sin programación definida';
        }

        $hora = substr((string) $e['hora'], 0, 5);

        switch ($e['frecuencia']) {
            case 'unica':
                return 'Una sola vez el ' . ($e['fecha_inicio'] ?? '?') . ' a las ' . $hora;

            case 'diaria':
                return 'Todos los días a las ' . $hora;

            case 'semanal':
                // "Lunes 13:00 · Jueves 15:00 · Sábado 17:00, 19:00"
                $porDia = [];
                foreach (self::horarios($e) as [$d, $h]) {
                    $porDia[$d][] = substr($h, 0, 5);
                }
                if (!$porDia) {
                    return 'Semanal sin días definidos';
                }
                $partes = [];
                foreach ($porDia as $d => $horas) {
                    $partes[] = (self::DIAS[$d] ?? '?') . ' ' . implode(', ', $horas);
                }
                return implode(' · ', $partes);

            case 'mensual':
                return 'Cada día ' . (int) ($e['dia_mes'] ?? 0) . ' del mes a las ' . $hora;
        }

        return 'Programación no reconocida';
    }

    /**
     * ¿La ejecución quedó fuera de la ventana de respaldo acordada?
     * Se usa al cerrar una ejecución para marcarla con advertencia.
     */
    public static function excedeVentana(array $e, int $duracionSegundos): bool
    {
        $ventana = (int) ($e['ventana_minutos'] ?? 0);
        return $ventana > 0 && $duracionSegundos > $ventana * 60;
    }
}

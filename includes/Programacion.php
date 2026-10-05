<?php
/**
 * Programacion — el "CUÁNDO" de la estrategia.
 *
 * Calcula cuándo toca la siguiente ejecución a partir de fecha de inicio,
 * hora, frecuencia, intervalo (cada N horas/días/semanas/meses) y días. El runner (scripts/runner.php) consulta esto para
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
        $n     = self::intervalo($e);

        $inicio = new DateTimeImmutable($e['fecha_inicio'] . ' ' . $hora);

        if ($e['frecuencia'] === 'unica') {
            return $inicio > $desde ? $inicio : null;
        }

        // Cada N horas, contando desde la fecha y hora de inicio.
        if ($e['frecuencia'] === 'horas') {
            if ($inicio > $desde) {
                return $inicio;
            }
            $paso = $n * 3600;
            $k = intdiv($desde->getTimestamp() - $inicio->getTimestamp(), $paso) + 1;
            return $inicio->setTimestamp($inicio->getTimestamp() + $k * $paso);
        }

        if ($e['frecuencia'] === 'semanal') {
            return self::proximaSemanal($e, $desde, $n);
        }

        // Punto de partida: hoy a la hora indicada, nunca antes de fecha_inicio.
        $candidata = new DateTimeImmutable($desde->format('Y-m-d') . ' ' . $hora);
        if ($candidata < $inicio) {
            $candidata = $inicio;
        }

        // Se avanza día a día hasta encontrar uno que cumpla la regla.
        // El límite crece con el intervalo (cada N meses puede tardar ~31·N días).
        $limite = 400 * $n;
        for ($i = 0; $i < $limite; $i++) {
            if ($candidata > $desde && self::aplicaEnDia($e, $candidata, $inicio, $n)) {
                return $candidata;
            }
            $candidata = $candidata->modify('+1 day');
        }

        return null;
    }

    /** Intervalo de repetición (cada N horas/días/semanas/meses). Mínimo 1. */
    public static function intervalo(array $e): int
    {
        return max(1, (int) ($e['intervalo'] ?? 1));
    }

    /**
     * Semanal: recorre el catálogo día-hora (como el ejecutor de la pizarra:
     * ¿es el día? ¿es la hora?) y toma el primer par posterior a $desde.
     * Con intervalo N, solo cuentan las semanas 0, N, 2N… desde la de inicio.
     */
    private static function proximaSemanal(array $e, DateTimeImmutable $desde, int $n = 1): ?DateTimeImmutable
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
        // Lunes de la semana de inicio: referencia para contar semanas.
        $lunesInicio = $inicio->modify('-' . ((int) $inicio->format('N') - 1) . ' days');

        // 7·N + 8 días: alcanza para llegar a la siguiente semana válida.
        $limite = 7 * $n + 8;
        for ($i = 0; $i < $limite; $i++) {
            $semanas = intdiv((int) $lunesInicio->diff($dia)->days, 7);
            if ($semanas % $n === 0) {
                $num = (int) $dia->format('N');
                foreach ($horarios as [$d, $hora]) {   // vienen ordenados por día y hora
                    $candidata = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $hora);
                    if ($d === $num && $candidata > $desde) {
                        return $candidata;
                    }
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

    private static function aplicaEnDia(array $e, DateTimeImmutable $fecha, DateTimeImmutable $inicio, int $n = 1): bool
    {
        switch ($e['frecuencia']) {
            case 'diaria':
                // Cada N días desde la fecha de inicio.
                $dias = (int) (new DateTimeImmutable($inicio->format('Y-m-d')))
                    ->diff(new DateTimeImmutable($fecha->format('Y-m-d')))->days;
                return $dias % $n === 0;

            case 'mensual':
                $diaMes = (int) ($e['dia_mes'] ?? 0);
                if ($diaMes < 1) { return false; }
                // Cada N meses desde el mes de inicio.
                $meses = ((int) $fecha->format('Y') - (int) $inicio->format('Y')) * 12
                       + ((int) $fecha->format('n') - (int) $inicio->format('n'));
                if ($meses % $n !== 0) { return false; }
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
        $n = self::intervalo($e);

        switch ($e['frecuencia']) {
            case 'unica':
                return 'Una sola vez el ' . ($e['fecha_inicio'] ?? '?') . ' a las ' . $hora;

            case 'horas':
                return ($n === 1 ? 'Cada hora' : 'Cada ' . $n . ' horas')
                     . ' desde el ' . ($e['fecha_inicio'] ?? '?') . ' a las ' . $hora;

            case 'diaria':
                return ($n === 1 ? 'Todos los días' : 'Cada ' . $n . ' días') . ' a las ' . $hora;

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
                return ($n === 1 ? '' : 'Cada ' . $n . ' semanas: ') . implode(' · ', $partes);

            case 'mensual':
                return ($n === 1 ? 'Cada mes' : 'Cada ' . $n . ' meses') . ', el día '
                     . (int) ($e['dia_mes'] ?? 0) . ' a las ' . $hora;
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
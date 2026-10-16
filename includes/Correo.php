<?php
/**
 * Notificación por correo cuando una ejecución falla.
 *
 * Flujo: el ejecutor corre la estrategia → falla → se registra la evidencia
 * → se envía un correo a todo el equipo con el resumen y el .log adjunto
 * → uno de los integrantes entra a la herramienta, revisa el problema y
 * relanza el respaldo de forma manual.
 *
 * Usa SMTP directamente (WAMP no trae servidor de correo, así que mail()
 * no sirve) sobre TLS implícito, sin librerías externas. La configuración
 * vive en includes/config.php (correo_*).
 *
 * Un fallo al enviar el correo NUNCA interrumpe la ejecución: solo queda
 * registrado en la bitácora.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/EstrategiaRepository.php';

class Correo
{
    private array $c;

    public function __construct()
    {
        $this->c = config();
    }

    public function activo(): bool
    {
        return (bool) ($this->c['correo_activo'] ?? false)
            && !empty($this->c['correo_usuario'])
            && !empty($this->destinatarios());
    }

    /** @return string[] */
    public function destinatarios(): array
    {
        $lista = $this->c['correo_destinatarios'] ?? [];
        return array_values(array_filter(array_map('trim', (array) $lista)));
    }

    /**
     * Avisa al equipo que una ejecución falló. Devuelve true si se envió.
     * Nunca lanza excepciones: el resultado queda en la bitácora.
     */
    public function notificarFallo(int $ejecucionId): bool
    {
        if (!$this->activo()) {
            return false;
        }

        try {
            $repo = new EstrategiaRepository();
            $ej = $repo->obtenerEjecucion($ejecucionId);
            if (!$ej || $ej['resultado'] !== 'fallido') {
                return false;
            }

            $codigo = RmanBuilder::codigo((int) $ej['estrategia_id']);
            $asunto = sprintf('[BackupGuard] FALLÓ %s "%s" sobre %s',
                $codigo, $ej['estrategia_nombre'], $ej['base_nombre']);

            $adjuntos = [];
            if (!empty($ej['archivo_log']) && is_file($ej['archivo_log'])) {
                $adjuntos[basename($ej['archivo_log'])] = (string) file_get_contents($ej['archivo_log']);
            } elseif (!empty($ej['salida_rman'])) {
                // Sin archivo en disco, la salida guardada en la base es el log.
                $adjuntos[$codigo . '_ejecucion_' . $ejecucionId . '.log'] = $ej['salida_rman'];
            }

            $this->enviar($this->destinatarios(), $asunto,
                $this->cuerpoHtml($ej, $codigo), $this->cuerpoTexto($ej, $codigo), $adjuntos);

            bitacora('correo_fallo_enviado', 'ejecuciones', $ejecucionId,
                'Aviso de fallo enviado a ' . implode(', ', $this->destinatarios()));
            return true;
        } catch (Throwable $ex) {
            try {
                bitacora('correo_fallo_error', 'ejecuciones', $ejecucionId,
                    mb_substr('No se pudo enviar el aviso: ' . $ex->getMessage(), 0, 500));
            } catch (Throwable $ignorado) {
            }
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Contenido del correo

    private function cuerpoHtml(array $ej, string $codigo): string
    {
        $h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $url = rtrim($this->c['url_app'] ?? 'http://localhost:8080', '/');

        $filas = [
            'Estrategia'  => $codigo . ' — ' . $ej['estrategia_nombre'],
            'Base'        => $ej['base_nombre'],
            'Origen'      => $ej['origen'] === 'programada' ? 'Programada (ejecutor automático)' : 'Manual',
            'Tipo'        => $ej['tipo_respaldo'],
            'Inicio'      => $ej['inicio'],
            'Fin'         => $ej['fin'] ?? '—',
            'Duración'    => $ej['duracion_seg'] !== null ? $ej['duracion_seg'] . ' s' : '—',
            'Código RMAN' => $ej['codigo_salida'] ?? '—',
            'Ubicación'   => $ej['ubicacion'] ?? '—',
            'Log'         => $ej['archivo_log'] ?: 'sin archivo (se adjunta la salida guardada)',
        ];
        $tabla = '';
        foreach ($filas as $k => $v) {
            $tabla .= '<tr><td style="padding:6px 12px;color:#6b7280;white-space:nowrap">' . $h($k) . '</td>'
                    . '<td style="padding:6px 12px;color:#111827;font-family:Consolas,monospace;font-size:13px">'
                    . $h($v) . '</td></tr>';
        }

        $errores = trim((string) $ej['mensaje_error']) !== ''
            ? '<pre style="margin:0;padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;'
              . 'color:#991b1b;font-size:12px;white-space:pre-wrap">' . $h($ej['mensaje_error']) . '</pre>'
            : '<p style="color:#6b7280">RMAN no reportó códigos de error. Revise el log adjunto.</p>';

        $simulado = (int) $ej['simulado'] === 1
            ? '<p style="margin:0 0 16px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;'
              . 'color:#92400e;font-size:13px">Ejecución <b>simulada</b>: es una prueba controlada, no se tocó la base.</p>'
            : '';

        $enlace = $h($url . '/ejecucion-detalle.php?id=' . $ej['id']);
        $enlaceEstrategia = $h($url . '/estrategia-detalle.php?id=' . $ej['estrategia_id']);

        return <<<HTML
<!doctype html>
<html><body style="margin:0;padding:24px;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">
  <div style="background:#b91c1c;padding:20px 24px;color:#ffffff">
    <div style="font-size:13px;opacity:.85">BackupGuard · Alerta de respaldo</div>
    <div style="font-size:20px;font-weight:600;margin-top:4px">Falló la ejecución #{$h($ej['id'])}</div>
  </div>
  <div style="padding:24px">
    {$simulado}
    <p style="margin:0 0 16px;color:#111827">El respaldo <b>{$h($codigo)} "{$h($ej['estrategia_nombre'])}"</b>
       sobre la base <b>{$h($ej['base_nombre'])}</b> no terminó correctamente.</p>
    <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:20px">{$tabla}</table>
    <div style="font-weight:600;color:#111827;margin-bottom:8px">Errores detectados</div>
    {$errores}
    <div style="margin-top:24px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;color:#1e3a8a;font-size:14px">
      <b>Qué hacer:</b> un integrante del equipo debe entrar a BackupGuard, revisar el log adjunto,
      corregir la causa y ejecutar la estrategia de forma manual (generar y aprobar el script si cambió).
    </div>
    <p style="margin:20px 0 0;font-size:14px">
      <a href="{$enlace}" style="color:#1d4ed8">Ver la evidencia de la ejecución</a> ·
      <a href="{$enlaceEstrategia}" style="color:#1d4ed8">Abrir la estrategia</a>
    </p>
    <p style="margin:6px 0 0;font-size:12px;color:#6b7280">Los enlaces funcionan desde la computadora donde está instalado BackupGuard.</p>
  </div>
  <div style="padding:14px 24px;background:#f9fafb;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb">
    Correo automático de BackupGuard. El log de RMAN va adjunto como evidencia.
  </div>
</div>
</body></html>
HTML;
    }

    private function cuerpoTexto(array $ej, string $codigo): string
    {
        $url = rtrim($this->c['url_app'] ?? 'http://localhost:8080', '/');
        return "BackupGuard - FALLÓ la ejecución #{$ej['id']}\n\n"
             . "Estrategia: {$codigo} - {$ej['estrategia_nombre']}\n"
             . "Base: {$ej['base_nombre']}\n"
             . "Origen: {$ej['origen']}\n"
             . "Inicio: {$ej['inicio']}  Fin: " . ($ej['fin'] ?? '-') . "\n"
             . "Log: " . ($ej['archivo_log'] ?: '-') . "\n\n"
             . "Errores:\n" . ($ej['mensaje_error'] ?: '(ver log adjunto)') . "\n\n"
             . "Qué hacer: revisar el log, corregir la causa y ejecutar la estrategia de forma manual.\n"
             . "Evidencia: {$url}/ejecucion-detalle.php?id={$ej['id']}\n";
    }

    // ------------------------------------------------------------------
    // Envío SMTP

    /**
     * @param string[]              $para
     * @param array<string, string> $adjuntos nombre => contenido
     */
    public function enviar(array $para, string $asunto, string $html, string $texto, array $adjuntos = []): void
    {
        $host    = $this->c['correo_host'] ?? 'smtp.gmail.com';
        $puerto  = (int) ($this->c['correo_puerto'] ?? 465);
        $usuario = $this->c['correo_usuario'] ?? '';
        $clave   = str_replace(' ', '', (string) ($this->c['correo_password'] ?? ''));
        $de      = $this->c['correo_remitente'] ?? $usuario;
        $nombre  = $this->c['correo_nombre'] ?? 'BackupGuard';

        $s = @stream_socket_client("ssl://$host:$puerto", $errno, $errstr, 20);
        if (!$s) {
            throw new RuntimeException("No se pudo conectar a $host:$puerto ($errstr)");
        }
        stream_set_timeout($s, 30);

        try {
            $this->esperar($s, 220);
            $this->comando($s, 'EHLO ' . (gethostname() ?: 'backupguard'), 250);
            $this->comando($s, 'AUTH LOGIN', 334);
            $this->comando($s, base64_encode($usuario), 334);
            $this->comando($s, base64_encode($clave), 235);
            $this->comando($s, "MAIL FROM:<$de>", 250);
            foreach ($para as $d) {
                $this->comando($s, "RCPT TO:<$d>", 250);
            }
            $this->comando($s, 'DATA', 354);

            $mensaje = $this->armarMensaje($de, $nombre, $para, $asunto, $html, $texto, $adjuntos);
            // Una línea que empieza con "." se duplica para no cortar el DATA.
            $mensaje = preg_replace('/^\./m', '..', $mensaje);
            fwrite($s, $mensaje . "\r\n.\r\n");
            $this->esperar($s, 250);
            $this->comando($s, 'QUIT', 221);
        } finally {
            fclose($s);
        }
    }

    private function armarMensaje(string $de, string $nombre, array $para, string $asunto,
                                  string $html, string $texto, array $adjuntos): string
    {
        $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $mixto = 'bg_mix_' . bin2hex(random_bytes(8));
        $alt   = 'bg_alt_' . bin2hex(random_bytes(8));

        $cab = [
            'Date: ' . date('r'),
            'From: ' . $enc($nombre) . " <$de>",
            'To: ' . implode(', ', $para),
            'Subject: ' . $enc($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@backupguard>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$mixto\"",
        ];

        $b64 = fn($t) => rtrim(chunk_split(base64_encode($t), 76, "\r\n"));

        $cuerpo  = "--$mixto\r\n";
        $cuerpo .= "Content-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n";
        $cuerpo .= "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . $b64($texto) . "\r\n";
        $cuerpo .= "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . $b64($html) . "\r\n";
        $cuerpo .= "--$alt--\r\n";

        foreach ($adjuntos as $archivo => $contenido) {
            $archivo = str_replace(['"', "\r", "\n"], '', $archivo);
            $cuerpo .= "--$mixto\r\n"
                     . "Content-Type: text/plain; charset=UTF-8; name=\"$archivo\"\r\n"
                     . "Content-Transfer-Encoding: base64\r\n"
                     . "Content-Disposition: attachment; filename=\"$archivo\"\r\n\r\n"
                     . $b64($contenido) . "\r\n";
        }
        $cuerpo .= "--$mixto--";

        return implode("\r\n", $cab) . "\r\n\r\n" . $cuerpo;
    }

    /** @param resource $s */
    private function comando($s, string $linea, int $esperado): void
    {
        fwrite($s, $linea . "\r\n");
        $this->esperar($s, $esperado);
    }

    /** @param resource $s */
    private function esperar($s, int $esperado): void
    {
        $respuesta = '';
        while (($l = fgets($s, 1024)) !== false) {
            $respuesta .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;  // última línea de una respuesta multilínea
            }
        }
        if ((int) substr($respuesta, 0, 3) !== $esperado) {
            throw new RuntimeException('El servidor de correo respondió: ' . trim($respuesta));
        }
    }
}

<?php
/**
 * Sesión y control de acceso.
 *
 * Tres roles, con separación de funciones deliberada: quien diseña la
 * estrategia no es necesariamente quien la aprueba y la ejecuta contra
 * la base. Esa separación es parte del control preventivo.
 */

function usuarioActual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function rolActual(): ?string
{
    return $_SESSION['usuario']['rol'] ?? null;
}

function esAdmin(): bool    { return rolActual() === 'admin'; }
function esOperador(): bool { return rolActual() === 'operador'; }
function esAuditor(): bool  { return rolActual() === 'auditor'; }

/** Puede crear y editar estrategias. */
function puedeEditar(): bool { return esAdmin() || esOperador(); }

/** Puede aprobar un script y ejecutarlo contra la base. */
function puedeEjecutar(): bool { return esAdmin(); }

function requiereLogin(): void
{
    if (!usuarioActual()) {
        $destino = $_SERVER['REQUEST_URI'] ?? 'login.php';
        header('Location: login.php?redirect=' . urlencode($destino));
        exit;
    }
}

function requiereAdmin(): void
{
    requiereLogin();
    if (!esAdmin()) {
        http_response_code(403);
        die('Esta acción está restringida a administradores.');
    }
}

function requiereEdicion(): void
{
    requiereLogin();
    if (!puedeEditar()) {
        http_response_code(403);
        die('Tu rol es de solo lectura.');
    }
}

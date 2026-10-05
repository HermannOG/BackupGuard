-- =====================================================================
-- BackupGuard — Migración: intervalo de repetición y dispositivo
--
-- Aplicar UNA vez sobre una base creada con una versión anterior de
-- schema.sql (phpMyAdmin → seleccionar la base backupguard → Importar).
-- Las bases nuevas creadas con el schema.sql actual ya lo traen.
--
-- Cubre dos puntos de la sección 7 del enunciado:
--   * "Cuándo respaldar → intervalo":
--       frecuencia 'horas'  → cada N horas
--       intervalo           → cada N horas / días / semanas / meses
--   * "Destino → identificación del dispositivo o almacenamiento":
--       dispositivo         → disco (DEVICE TYPE DISK) o cinta (SBT)
--       dispositivo_id      → nombre del dispositivo (ej. "NAS-BACKUP-01")
-- =====================================================================

SET NAMES utf8mb4;
USE backupguard;   -- evita el error #1046 si no hay base seleccionada

ALTER TABLE estrategias
    MODIFY COLUMN frecuencia ENUM('unica','horas','diaria','semanal','mensual') NOT NULL DEFAULT 'diaria',
    ADD COLUMN intervalo SMALLINT NOT NULL DEFAULT 1 AFTER frecuencia,
    ADD COLUMN dispositivo ENUM('disco','cinta') NOT NULL DEFAULT 'disco' AFTER ventana_minutos,
    ADD COLUMN dispositivo_id VARCHAR(150) NULL AFTER dispositivo;

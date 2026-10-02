-- =====================================================================
-- BackupGuard — Migración: catálogo de estrategias por día y hora
--
-- Aplicar UNA vez sobre una base creada con una versión anterior de
-- schema.sql (phpMyAdmin → Importar). Las bases nuevas ya lo traen.
--
--   estrategia_horarios      pares (día, hora) de cada estrategia semanal:
--                            permite "lunes 13:00, jueves 15:00, sábado 17:00".
--   estrategias.archivo_rman ruta del archivo EST###.rma aprobado en disco.
--   ejecuciones.archivo_log  ruta del log de RMAN que dejó cada ejecución.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS estrategia_horarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    estrategia_id INT NOT NULL,
    dia_semana    TINYINT NOT NULL,   -- 1=lunes ... 7=domingo
    hora          TIME NOT NULL,
    UNIQUE KEY uq_horario (estrategia_id, dia_semana, hora),
    CONSTRAINT fk_horarios_estrategia FOREIGN KEY (estrategia_id)
        REFERENCES estrategias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE estrategias ADD COLUMN archivo_rman VARCHAR(400) NULL AFTER aprobado_en;
ALTER TABLE ejecuciones ADD COLUMN archivo_log  VARCHAR(400) NULL AFTER ubicacion;

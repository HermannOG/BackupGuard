-- =====================================================================
-- BackupGuard — Esquema de base de datos (MySQL / MariaDB)
-- Curso EIF402: Administración de Bases de Datos — II ciclo 2026
--
-- Esta base guarda las ESTRATEGIAS y la EVIDENCIA. Las bases Oracle
-- respaldadas viven aparte: aquí solo se registran sus datos de conexión.
--
-- Ejecutar completo en phpMyAdmin (pestaña "Importar") sobre una base vacía.
-- =====================================================================

SET NAMES utf8mb4;

-- ============================== USUARIOS =============================

CREATE TABLE IF NOT EXISTS usuarios (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre_usuario  VARCHAR(100) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(150) NULL,
    rol             ENUM('admin','operador','auditor') NOT NULL DEFAULT 'operador',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_nombre (nombre_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles:
--   admin    — registra bases, aprueba y ejecuta estrategias.
--   operador — crea y edita estrategias, pero no aprueba ni ejecuta.
--   auditor  — solo lectura: historial, evidencia y alertas.

-- ========================= BASES DE DATOS ORACLE =====================

CREATE TABLE IF NOT EXISTS bases_datos (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    nombre             VARCHAR(120) NOT NULL,
    descripcion        VARCHAR(255) NULL,
    ambiente           ENUM('desarrollo','pruebas','produccion') NOT NULL DEFAULT 'pruebas',
    tns_alias          VARCHAR(100) NULL,
    host               VARCHAR(150) NULL,
    puerto             INT NULL,
    service_name       VARCHAR(100) NULL,
    usuario            VARCHAR(100) NOT NULL,
    password_enc       VARBINARY(512) NOT NULL,
    conectar_as_sysdba TINYINT(1) NOT NULL DEFAULT 1,
    modo_archivado     ENUM('ARCHIVELOG','NOARCHIVELOG','DESCONOCIDO') NOT NULL DEFAULT 'DESCONOCIDO',
    ultimo_chequeo     DATETIME NULL,
    activo             TINYINT(1) NOT NULL DEFAULT 1,
    creado_en          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================ ESTRATEGIAS ============================

CREATE TABLE IF NOT EXISTS estrategias (
    id              INT AUTO_INCREMENT PRIMARY KEY,

    -- ----- Información general -----
    nombre          VARCHAR(150) NOT NULL,
    descripcion     TEXT NULL,
    base_datos_id   INT NOT NULL,
    responsable     VARCHAR(150) NULL,
    prioridad       ENUM('alta','media','baja') NOT NULL DEFAULT 'media',
    justificacion_prioridad TEXT NULL,
    estado          ENUM('activa','inactiva') NOT NULL DEFAULT 'inactiva',

    -- ----- QUÉ respaldar -----
    alcance             ENUM('base_completa','tablespaces','datafiles') NOT NULL DEFAULT 'base_completa',
    incluir_controlfile TINYINT(1) NOT NULL DEFAULT 1,
    incluir_spfile      TINYINT(1) NOT NULL DEFAULT 1,
    incluir_archivelogs TINYINT(1) NOT NULL DEFAULT 0,
    borrar_archivelogs  TINYINT(1) NOT NULL DEFAULT 0,

    -- ----- CÓMO respaldar -----
    tipo_respaldo   ENUM('completo','incremental_0','incremental_1') NOT NULL DEFAULT 'completo',
    modalidad       ENUM('diferencial','acumulativo') NULL,  -- solo aplica a incremental_1
    comprimido      TINYINT(1) NOT NULL DEFAULT 0,
    paralelismo     TINYINT NOT NULL DEFAULT 1,
    retencion_dias  INT NULL,
    verificar_respaldo TINYINT(1) NOT NULL DEFAULT 1,  -- agrega VALIDATE / RESTORE ... VALIDATE

    -- ----- CUÁNDO respaldar -----
    fecha_inicio    DATE NULL,
    hora            TIME NULL,
    frecuencia      ENUM('unica','diaria','semanal','mensual') NOT NULL DEFAULT 'diaria',
    dias_semana     VARCHAR(20) NULL,   -- '1,3,5' (1=lunes ... 7=domingo)
    dia_mes         TINYINT NULL,
    ventana_minutos INT NULL,           -- duración máxima aceptable de la ventana

    -- ----- Destino -----
    destino         VARCHAR(255) NULL,  -- carpeta o 'FRA' (Fast Recovery Area)

    -- ----- Script y aprobación -----
    script_rman     MEDIUMTEXT NULL,
    script_generado_en DATETIME NULL,
    aprobado        TINYINT(1) NOT NULL DEFAULT 0,
    aprobado_por    VARCHAR(100) NULL,
    aprobado_en     DATETIME NULL,

    ultima_ejecucion   DATETIME NULL,
    proxima_ejecucion  DATETIME NULL,

    creado_por      VARCHAR(100) NULL,
    creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_estrategias_bd FOREIGN KEY (base_datos_id) REFERENCES bases_datos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tablespaces / datafiles concretos cuando el alcance no es la base completa.
CREATE TABLE IF NOT EXISTS estrategia_objetos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    estrategia_id INT NOT NULL,
    tipo          ENUM('tablespace','datafile') NOT NULL,
    nombre        VARCHAR(400) NOT NULL,
    CONSTRAINT fk_objetos_estrategia FOREIGN KEY (estrategia_id)
        REFERENCES estrategias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ======================= EVIDENCIA DE EJECUCIÓN ======================

CREATE TABLE IF NOT EXISTS ejecuciones (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    estrategia_id    INT NOT NULL,
    base_datos_id    INT NOT NULL,
    origen           ENUM('manual','programada') NOT NULL DEFAULT 'manual',
    tipo_respaldo    VARCHAR(40) NOT NULL,
    inicio           DATETIME NOT NULL,
    fin              DATETIME NULL,
    duracion_seg     INT NULL,
    resultado        ENUM('en_curso','exitoso','advertencia','fallido') NOT NULL DEFAULT 'en_curso',
    codigo_salida    INT NULL,
    script_ejecutado MEDIUMTEXT NULL,
    salida_rman      LONGTEXT NULL,
    mensaje_error    TEXT NULL,
    ubicacion        VARCHAR(400) NULL,
    archivos_generados INT NULL,
    tamano_bytes     BIGINT NULL,
    simulado         TINYINT(1) NOT NULL DEFAULT 0,
    ejecutado_por    VARCHAR(100) NULL,
    CONSTRAINT fk_ejec_estrategia FOREIGN KEY (estrategia_id) REFERENCES estrategias(id),
    CONSTRAINT fk_ejec_bd FOREIGN KEY (base_datos_id) REFERENCES bases_datos(id),
    INDEX idx_ejec_estrategia_inicio (estrategia_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ======================== ALERTAS PREVENTIVAS ========================

CREATE TABLE IF NOT EXISTS alertas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(60) NOT NULL,   -- ej. SIN_PROGRAMACION, NOARCHIVELOG
    severidad     ENUM('informacion','recomendacion','advertencia','critica') NOT NULL,
    mensaje       VARCHAR(500) NOT NULL,
    estrategia_id INT NULL,
    base_datos_id INT NULL,
    detectada_en  DATETIME NOT NULL,
    atendida      TINYINT(1) NOT NULL DEFAULT 0,
    atendida_en   DATETIME NULL,
    atendida_por  VARCHAR(100) NULL,
    INDEX idx_alertas_codigo (codigo, atendida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== BITÁCORA DE AUDITORÍA =========================
-- Registro de quién hizo qué: exigido por el enfoque de control preventivo
-- (toda acción sobre una estrategia debe quedar trazada).

CREATE TABLE IF NOT EXISTS bitacora (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(100) NULL,
    accion      VARCHAR(80) NOT NULL,
    entidad     VARCHAR(40) NULL,
    entidad_id  INT NULL,
    detalle     VARCHAR(500) NULL,
    ocurrido_en DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

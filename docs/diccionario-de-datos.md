# Diccionario de datos — BackupGuard

Describe cada tabla y columna de la base MySQL/MariaDB `backupguard`, tal como la crean [`database/schema.sql`](../database/schema.sql) y [`database/migracion-catalogo.sql`](../database/migracion-catalogo.sql).

Esta base guarda **el catálogo de estrategias y la evidencia**. Las bases Oracle que se respaldan viven aparte: aquí solo se registran sus datos de conexión.

**Motor:** InnoDB · **Juego de caracteres:** `utf8mb4` · **Zona horaria:** la de `config.php` (`America/Costa_Rica`), aplicada a PHP y a la sesión de MySQL.

---

## Contenido

1. [Modelo general](#1-modelo-general)
2. [`usuarios`](#2-usuarios)
3. [`bases_datos`](#3-bases_datos)
4. [`estrategias`](#4-estrategias)
5. [`estrategia_objetos`](#5-estrategia_objetos)
6. [`estrategia_horarios`](#6-estrategia_horarios)
7. [`ejecuciones`](#7-ejecuciones)
8. [`alertas`](#8-alertas)
9. [`bitacora`](#9-bitacora)
10. [Migración `migracion-catalogo.sql`](#10-migración-migracion-catalogosql)
11. [Datos que viven en disco](#11-datos-que-viven-en-disco)

**Convenciones de las tablas de columnas:**

| Columna | Significado |
|---|---|
| **Nulo** | `No` = obligatorio (`NOT NULL`); `Sí` = admite `NULL`. |
| **Defecto** | Valor que toma si el `INSERT` no lo indica. |
| **Clave** | `PK` primaria · `FK` foránea · `UQ` única · `IX` índice. |

---

## 1. Modelo general

```
usuarios                (sin relaciones: se referencia por nombre_usuario en texto)

bases_datos 1 ───< N estrategias 1 ───< N estrategia_objetos     (ON DELETE CASCADE)
     │                    │        1 ───< N estrategia_horarios    (ON DELETE CASCADE)
     │                    │
     │                    └──────< N ejecuciones
     └───────────────────────────< N ejecuciones

alertas    → estrategia_id / base_datos_id   (sin FK: referencia informativa)
bitacora   → entidad + entidad_id             (referencia genérica, sin FK)
```

| Relación | Tipo | Al borrar el padre |
|---|---|---|
| `estrategias.base_datos_id` → `bases_datos.id` | N:1 | **Restringido.** Por eso las bases no se borran: se dan de baja con `activo = 0`. |
| `estrategia_objetos.estrategia_id` → `estrategias.id` | N:1 | Se borran en cascada. |
| `estrategia_horarios.estrategia_id` → `estrategias.id` | N:1 | Se borran en cascada. |
| `ejecuciones.estrategia_id` → `estrategias.id` | N:1 | **Restringido.** La aplicación borra primero las ejecuciones y alertas de la estrategia (`EstrategiaRepository::eliminar`). |
| `ejecuciones.base_datos_id` → `bases_datos.id` | N:1 | Restringido. |

Las fechas en `DATETIME` se escriben con la hora local configurada. Las columnas `TIMESTAMP` (`creado_en`, `actualizado_en`) las llena MySQL solo.

---

## 2. `usuarios`

Personas que entran a la web y su rol.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `nombre_usuario` | VARCHAR(100) | No | — | UQ | Nombre para iniciar sesión. Es el que se guarda como autor en las demás tablas. |
| `password_hash` | VARCHAR(255) | No | — | | Contraseña con `password_hash()` de PHP (bcrypt). Nunca en texto plano. |
| `nombre_completo` | VARCHAR(150) | Sí | NULL | | Nombre para mostrar; se propone como *responsable* en las estrategias nuevas. |
| `rol` | ENUM | No | `operador` | | Ver valores abajo. |
| `activo` | TINYINT(1) | No | 1 | | 1 = puede entrar; 0 = deshabilitado. |
| `creado_en` | TIMESTAMP | No | CURRENT_TIMESTAMP | | Alta del usuario. |

**`rol`:**

| Valor | Puede |
|---|---|
| `admin` | Todo: registrar bases, aprobar scripts y ejecutar respaldos. |
| `operador` | Crear y editar estrategias y generar scripts; no aprobar ni ejecutar. |
| `auditor` | Solo lectura: historial, evidencia y alertas. |

El primer `admin` se crea en `crear-admin.php`; esa pantalla se cierra sola cuando ya existe uno.

---

## 3. `bases_datos`

Bases Oracle registradas para respaldar: cómo conectarse y en qué modo de archivado están.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `nombre` | VARCHAR(120) | No | — | | Nombre para mostrar (ej. `XE`). |
| `descripcion` | VARCHAR(255) | Sí | NULL | | Para qué se usa la base. |
| `ambiente` | ENUM(`desarrollo`,`pruebas`,`produccion`) | No | `pruebas` | | `produccion` activa advertencias extra (probar antes de aprobar; alerta si la estrategia está inactiva). |
| `tns_alias` | VARCHAR(100) | Sí | NULL | | Alias de `tnsnames.ora`. Si tiene valor, **se usa en vez de** host/puerto/servicio. |
| `host` | VARCHAR(150) | Sí | NULL | | Servidor Oracle (ej. `localhost`). |
| `puerto` | INT | Sí | NULL | | Puerto del listener (ej. `1521`). |
| `service_name` | VARCHAR(100) | Sí | NULL | | Servicio Oracle. En modo real: `XE` (el CDB completo). |
| `usuario` | VARCHAR(100) | No | — | | Usuario Oracle para RMAN y para *Verificar* (ej. `c##bgbackup`). |
| `password_enc` | VARBINARY(512) | No | — | | Contraseña Oracle cifrada con **AES-256-GCM**: 12 bytes de IV + 16 de etiqueta + texto cifrado. La clave sale de `encryption_key` en `config.php`; si esa clave cambia, este dato ya no se puede descifrar. |
| `conectar_as_sysdba` | TINYINT(1) | No | 1 | | **1** = RMAN se conecta `AS SYSDBA`. **0** = se conecta `AS SYSBACKUP` (lo correcto para `c##bgbackup`). Es la casilla *SYSDBA* del formulario. |
| `modo_archivado` | ENUM(`ARCHIVELOG`,`NOARCHIVELOG`,`DESCONOCIDO`) | No | `DESCONOCIDO` | | Leído de `v$database.log_mode` al presionar **Verificar**. Alimenta las validaciones y alertas. |
| `ultimo_chequeo` | DATETIME | Sí | NULL | | Última vez que se pulsó **Verificar** con éxito. Pasada una semana se genera la alerta `SIN_CHEQUEO`. |
| `activo` | TINYINT(1) | No | 1 | | 0 = dada de baja. No se borra físicamente porque tiene estrategias y ejecuciones asociadas. |
| `creado_en` | TIMESTAMP | No | CURRENT_TIMESTAMP | | Alta del registro. |

---

## 4. `estrategias`

**El catálogo de estrategias.** Cada fila responde *qué* se respalda, *cómo* y *cuándo*, y guarda el script RMAN generado y su aprobación. Su código de catálogo es `EST` + `id` con tres dígitos (`id = 7` → `EST007`); no se guarda, se calcula.

### Información general

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. Da el código `EST###` y el nombre del `.rma`. |
| `nombre` | VARCHAR(150) | No | — | | Nombre descriptivo. |
| `descripcion` | TEXT | Sí | NULL | | Qué protege y por qué. |
| `base_datos_id` | INT | No | — | FK | Base Oracle que se respalda → `bases_datos.id`. |
| `responsable` | VARCHAR(150) | Sí | NULL | | Persona a cargo. |
| `prioridad` | ENUM(`alta`,`media`,`baja`) | No | `media` | | `alta` activa la alerta `SIN_RESPALDO_RECIENTE` si pasan 48 h sin respaldo. |
| `justificacion_prioridad` | TEXT | Sí | NULL | | Criterio con que se asignó la prioridad. |
| `estado` | ENUM(`activa`,`inactiva`) | No | `inactiva` | | Solo las **activas** (y aprobadas) las corre el ejecutor. Una inactiva se puede ejecutar a mano. |

### QUÉ respaldar

| Columna | Tipo | Nulo | Defecto | Descripción | RMAN que genera |
|---|---|---|---|---|---|
| `alcance` | ENUM(`base_completa`,`tablespaces`,`datafiles`) | No | `base_completa` | Qué parte de la base. Para tablespaces o datafiles, la lista está en `estrategia_objetos`. | `BACKUP … DATABASE` / `TABLESPACE a, b` / `DATAFILE 1, 4` |
| `incluir_controlfile` | TINYINT(1) | No | 1 | Respaldar el control file. | `INCLUDE CURRENT CONTROLFILE` (base completa) o `BACKUP CURRENT CONTROLFILE` aparte |
| `incluir_spfile` | TINYINT(1) | No | 1 | Respaldar el SPFILE. | `BACKUP SPFILE` |
| `incluir_archivelogs` | TINYINT(1) | No | 0 | Respaldar los archived redo logs. Error de validación si la base está en NOARCHIVELOG. | `PLUS ARCHIVELOG` |
| `borrar_archivelogs` | TINYINT(1) | No | 0 | Borrar los archived logs una vez respaldados. Exige `incluir_archivelogs = 1`. | `DELETE INPUT` |

### CÓMO respaldar

| Columna | Tipo | Nulo | Defecto | Descripción | RMAN que genera |
|---|---|---|---|---|---|
| `tipo_respaldo` | ENUM(`completo`,`incremental_0`,`incremental_1`) | No | `completo` | Tipo de backup. | `BACKUP AS BACKUPSET` / `INCREMENTAL LEVEL 0` / `INCREMENTAL LEVEL 1` |
| `modalidad` | ENUM(`diferencial`,`acumulativo`) | Sí | NULL | Solo para `incremental_1`; en los demás tipos queda NULL. | (nada) / `CUMULATIVE` |
| `comprimido` | TINYINT(1) | No | 0 | Comprimir el backup. | `AS COMPRESSED BACKUPSET` |
| `paralelismo` | TINYINT | No | 1 | Canales en paralelo (1 a 8). | n × `ALLOCATE CHANNEL chN DEVICE TYPE DISK` |
| `retencion_dias` | INT | Sí | NULL | Días que se conservan los backups. NULL = sin política. | `CONFIGURE RETENTION POLICY…` + `CROSSCHECK` + `DELETE NOPROMPT OBSOLETE` |
| `verificar_respaldo` | TINYINT(1) | No | 1 | Comprobar después que el backup sirve para restaurar. | `RESTORE … VALIDATE` (datos, control file, SPFILE) |

### CUÁNDO respaldar

| Columna | Tipo | Nulo | Defecto | Descripción |
|---|---|---|---|---|
| `fecha_inicio` | DATE | Sí | NULL | Primer día en que puede correr. Obligatoria si la estrategia está activa. |
| `hora` | TIME | Sí | NULL | Hora general. En `diaria`, `mensual` y `unica` es **la** hora. En `semanal` solo la usan los días sin hora propia en `estrategia_horarios`; si no se indica, se guarda la más temprana del catálogo. |
| `frecuencia` | ENUM(`unica`,`diaria`,`semanal`,`mensual`) | No | `diaria` | Regla de repetición. |
| `dias_semana` | VARCHAR(20) | Sí | NULL | Días marcados, separados por coma: `1` = lunes … `7` = domingo (ej. `1,4,6`). Las horas de cada día están en `estrategia_horarios`. |
| `dia_mes` | TINYINT | Sí | NULL | Día del mes para `mensual` (1–31). Si el mes no llega a ese día, corre el último día del mes. |
| `ventana_minutos` | INT | Sí | NULL | Duración máxima aceptable. Si una ejecución exitosa la supera, queda como `advertencia`. |

### Destino, script y aprobación

| Columna | Tipo | Nulo | Defecto | Descripción |
|---|---|---|---|---|
| `destino` | VARCHAR(255) | Sí | NULL | Carpeta de los backups (ej. `C:\BackupGuard_RMAN\XE`). Vacío o `FRA` = Fast Recovery Area, sin cláusula `FORMAT`. El ejecutor la crea si no existe. |
| `script_rman` | MEDIUMTEXT | Sí | NULL | Script RMAN generado por `RmanBuilder`. Se borra al editar la estrategia. |
| `script_generado_en` | DATETIME | Sí | NULL | Cuándo se generó. |
| `aprobado` | TINYINT(1) | No | 0 | 1 = un administrador aprobó el script. Sin aprobación no se ejecuta, ni a mano ni sola. Editar o regenerar la vuelve a 0. |
| `aprobado_por` | VARCHAR(100) | Sí | NULL | `nombre_usuario` de quien aprobó. |
| `aprobado_en` | DATETIME | Sí | NULL | Cuándo se aprobó. |
| `archivo_rman` | VARCHAR(400) | Sí | NULL | **(migración)** Ruta del `EST###.rma` aprobado en disco (ej. `C:\BackupGuard_RMAN\scripts\EST007.rma`). Se llena al aprobar y vuelve a NULL (y el archivo se borra) cuando la estrategia pierde la aprobación. |

### Seguimiento

| Columna | Tipo | Nulo | Defecto | Descripción |
|---|---|---|---|---|
| `ultima_ejecucion` | DATETIME | Sí | NULL | Fin de la última ejecución. |
| `proxima_ejecucion` | DATETIME | Sí | NULL | Calculada por `Programacion::proxima()` al guardar, activar y después de cada ejecución. NULL si está inactiva o no se puede programar. **Es lo que mira el ejecutor:** corre las activas y aprobadas con `proxima_ejecucion <= NOW()`. |
| `creado_por` | VARCHAR(100) | Sí | NULL | `nombre_usuario` que la creó. |
| `creado_en` | TIMESTAMP | No | CURRENT_TIMESTAMP | Alta. |
| `actualizado_en` | TIMESTAMP | No | CURRENT_TIMESTAMP ON UPDATE | Última modificación de la fila. |

---

## 5. `estrategia_objetos`

Tablespaces o datafiles concretos cuando la estrategia no respalda la base completa.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `estrategia_id` | INT | No | — | FK | → `estrategias.id`, `ON DELETE CASCADE`. |
| `tipo` | ENUM(`tablespace`,`datafile`) | No | — | | Coincide con el `alcance` de la estrategia. |
| `nombre` | VARCHAR(400) | No | — | | Nombre del tablespace (ej. `USERS`), número del datafile (`4`) o su ruta completa. |

Se reescribe completa cada vez que se guarda la estrategia.

---

## 6. `estrategia_horarios`

**(migración)** El **catálogo día-hora** de la pizarra: en qué días y a qué horas corre cada estrategia **semanal**. Permite una hora distinta por día, o varias en el mismo día.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `estrategia_id` | INT | No | — | FK, UQ | → `estrategias.id`, `ON DELETE CASCADE`. |
| `dia_semana` | TINYINT | No | — | UQ | 1 = lunes … 7 = domingo. |
| `hora` | TIME | No | — | UQ | Hora de ejecución ese día. |

- **Clave única** `uq_horario (estrategia_id, dia_semana, hora)`: el mismo día y hora no se repite.
- Solo tiene filas para estrategias con `frecuencia = 'semanal'`; se reescribe al guardar.
- Si una estrategia semanal **no** tiene filas (por ejemplo, creada antes de la migración), se usan `dias_semana` con la `hora` general.

Ejemplo — *lunes 13:00, jueves 15:00, sábado 17:00 y 19:00* para `EST006`:

| estrategia_id | dia_semana | hora |
|---|---|---|
| 6 | 1 | 13:00:00 |
| 6 | 4 | 15:00:00 |
| 6 | 6 | 17:00:00 |
| 6 | 6 | 19:00:00 |

---

## 7. `ejecuciones`

**La evidencia.** Una fila por cada vez que se corrió una estrategia, a mano o por el ejecutor.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Número de evidencia (*evidencia #19*). |
| `estrategia_id` | INT | No | — | FK, IX | → `estrategias.id`. |
| `base_datos_id` | INT | No | — | FK | → `bases_datos.id`. Se guarda aparte por si la estrategia cambia de base después. |
| `origen` | ENUM(`manual`,`programada`) | No | `manual` | | `manual` = botón *Ejecutar ahora*; `programada` = la lanzó el ejecutor (`runner.php`). |
| `tipo_respaldo` | VARCHAR(40) | No | — | | Copia del tipo al momento de correr (ej. `completo`, `incremental_1 / diferencial`). |
| `inicio` | DATETIME | No | — | IX | Arranque. |
| `fin` | DATETIME | Sí | NULL | | Fin. NULL mientras está `en_curso`. |
| `duracion_seg` | INT | Sí | NULL | | `fin - inicio`, en segundos. |
| `resultado` | ENUM(`en_curso`,`exitoso`,`advertencia`,`fallido`) | No | `en_curso` | | Ver valores abajo. |
| `codigo_salida` | INT | Sí | NULL | | Código con que terminó `rman.exe` (0 = sin error). `-1` si falló antes de llamar a RMAN. |
| `script_ejecutado` | MEDIUMTEXT | Sí | NULL | | Script **exacto** que se corrió, aunque la estrategia se haya modificado después. |
| `salida_rman` | LONGTEXT | Sí | NULL | | Log completo de RMAN, convertido a UTF-8. |
| `mensaje_error` | TEXT | Sí | NULL | | Líneas `RMAN-`/`ORA-` encontradas y notas propias (ej. *"no se encontraron archivos nuevos"*). |
| `ubicacion` | VARCHAR(400) | Sí | NULL | | Carpeta destino, o `Fast Recovery Area`. |
| `archivo_log` | VARCHAR(400) | Sí | NULL | | **(migración)** Ruta del `.log` en disco (ej. `C:\BackupGuard_RMAN\XE\EST007_20261001_223516.log`). Junto a él queda la copia `.rma` con el mismo nombre. |
| `archivos_generados` | INT | Sí | NULL | | Piezas nuevas encontradas en el destino (sin contar el `.log` ni el `.rma`). NULL si el destino no se puede inspeccionar (FRA) o la ejecución falló. |
| `tamano_bytes` | BIGINT | Sí | NULL | | Suma del tamaño de esas piezas. |
| `simulado` | TINYINT(1) | No | 0 | | 1 = no se llamó a RMAN (`modo_simulacion` o *Simular un fallo*). 0 = RMAN real. |
| `ejecutado_por` | VARCHAR(100) | Sí | NULL | | `nombre_usuario` si fue manual; `ejecutor` si fue programada (registros anteriores dicen `scheduler`). |

**Índice** `idx_ejec_estrategia_inicio (estrategia_id, inicio)`: acelera el historial por estrategia.

**`resultado`:**

| Valor | Cuándo |
|---|---|
| `en_curso` | Se escribe al arrancar. Si el proceso muere a mitad, queda así como huella. |
| `exitoso` | RMAN terminó en 0, sin errores en el log, con archivos nuevos y dentro de la ventana. |
| `advertencia` | Hubo códigos `RMAN-`/`ORA-` no graves, *warning*, ningún archivo nuevo en el destino, o se excedió la ventana. |
| `fallido` | Código de salida distinto de 0, errores graves en el log (`RMAN-03009`, `RMAN-06059`, `ORA-19809`, `ORA-19804`, `ORA-01157`, `ORA-01578`), o una excepción antes de terminar. |

---

## 8. `alertas`

Control preventivo: problemas detectados al evaluar las reglas de `includes/Alertas.php`. Se recalcula al abrir el tablero o la página de alertas, y en el ejecutor después de cada ejecución o cada 10 minutos.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `codigo` | VARCHAR(60) | No | — | IX | Regla que la generó (tabla abajo). |
| `severidad` | ENUM(`informacion`,`recomendacion`,`advertencia`,`critica`) | No | — | | Gravedad. |
| `mensaje` | VARCHAR(500) | No | — | | Texto que se muestra. |
| `estrategia_id` | INT | Sí | NULL | | Estrategia afectada (sin FK). |
| `base_datos_id` | INT | Sí | NULL | | Base afectada (sin FK). |
| `detectada_en` | DATETIME | No | — | | Cuándo se detectó. |
| `atendida` | TINYINT(1) | No | 0 | IX | 1 = alguien la marcó como atendida. |
| `atendida_en` | DATETIME | Sí | NULL | | Cuándo. |
| `atendida_por` | VARCHAR(100) | Sí | NULL | | `nombre_usuario` de quien la atendió. |

En cada evaluación se **borran las no atendidas** y se vuelven a calcular. Las atendidas se conservan como historial y no se repiten mientras la condición siga igual.

**`codigo`:**

| Código | Severidad | Se genera cuando… |
|---|---|---|
| `NOARCHIVELOG` | advertencia | Una base activa está en NOARCHIVELOG. |
| `SIN_CHEQUEO` | informacion | Hace más de una semana que no se verifica una base. |
| `SIN_PROGRAMACION` | advertencia | Una estrategia activa no tiene hora, fecha de inicio o próxima ejecución calculable. |
| `SIN_APROBACION` | advertencia | Una estrategia activa no tiene su script aprobado. |
| `INACTIVA_PRODUCCION` | advertencia | Una estrategia sobre una base de **producción** está inactiva. |
| `EJECUCION_FALLIDA` | critica | La última ejecución de una estrategia, en los últimos 7 días, fue `fallido`. |
| `NO_EJECUTADO` | critica | Una estrategia activa tenía que correr hace más de 2 horas y no corrió (¿ejecutor apagado?). |
| `SIN_RESPALDO_RECIENTE` | critica | Una estrategia activa de prioridad **alta** lleva más de 48 h sin ejecutarse. |
| `SIN_ESTRATEGIA` | advertencia | Una base activa no tiene ninguna estrategia. |
| `ARCHIVELOGS_FUERA` | recomendacion | Una estrategia activa sobre una base en ARCHIVELOG no respalda los archived logs. |

---

## 9. `bitacora`

Auditoría: quién hizo qué y cuándo.

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | No | — | PK | Identificador. |
| `usuario` | VARCHAR(100) | Sí | NULL | | `nombre_usuario` de la sesión. NULL si la acción la hizo el ejecutor (no tiene sesión). |
| `accion` | VARCHAR(80) | No | — | | Qué se hizo (tabla abajo). |
| `entidad` | VARCHAR(40) | Sí | NULL | | Tabla afectada: `estrategias`, `bases_datos`, `alertas`, `usuarios`. |
| `entidad_id` | INT | Sí | NULL | | `id` de la fila afectada. |
| `detalle` | VARCHAR(500) | Sí | NULL | | Información extra (nombre, resultado, modo detectado…). |
| `ocurrido_en` | DATETIME | No | — | | Momento de la acción. |

**`accion`:**

| Acción | Entidad | Cuándo |
|---|---|---|
| `login` | usuarios | Inicio de sesión. |
| `registrar_base` | bases_datos | Alta de una base Oracle. |
| `baja_base` | bases_datos | Baja de una base (`activo = 0`). |
| `chequeo_base` | bases_datos | Botón **Verificar**; el detalle dice el modo detectado. |
| `crear_estrategia` / `editar_estrategia` | estrategias | Guardar el formulario. |
| `activar_estrategia` / `desactivar_estrategia` | estrategias | Botones Activar / Desactivar. |
| `generar_script` | estrategias | Botón **Generar script**. |
| `aprobar_script` | estrategias | Botón **Aprobar**; en ese momento se escribe el `.rma`. |
| `ejecucion` | estrategias | Fin de una ejecución; el detalle trae el resultado y si fue simulada. |
| `eliminar_estrategia` | estrategias | Borrado de una estrategia con su historial. |
| `atender_alerta` | alertas | Marcar una alerta como atendida. |

---

## 10. Migración `migracion-catalogo.sql`

Lleva una base creada con el `schema.sql` **anterior** al estado actual. Se aplica **una sola vez**. Una base creada con el `schema.sql` actual ya lo trae todo y **no** debe migrarse (daría *Duplicate column name*).

| Cambio | Objeto | Para qué |
|---|---|---|
| `CREATE TABLE estrategia_horarios` | Tabla nueva ([sección 6](#6-estrategia_horarios)) | Catálogo día-hora: horas distintas por día. |
| `ALTER TABLE estrategias ADD COLUMN archivo_rman VARCHAR(400) NULL AFTER aprobado_en` | Columna nueva | Ruta del `EST###.rma` aprobado en disco. |
| `ALTER TABLE ejecuciones ADD COLUMN archivo_log VARCHAR(400) NULL AFTER ubicacion` | Columna nueva | Ruta del log de RMAN de cada ejecución. |

**Efecto sobre datos existentes:** ninguno destructivo. Las estrategias semanales viejas siguen funcionando con `dias_semana` + `hora`. Las estrategias aprobadas antes no tienen `archivo_rman` hasta que se ejecutan o se vuelven a aprobar. Las ejecuciones viejas quedan con `archivo_log = NULL`.

**Cómo saber si ya está aplicada:**

```sql
SHOW TABLES LIKE 'estrategia_horarios';
SHOW COLUMNS FROM estrategias LIKE 'archivo_rman';
SHOW COLUMNS FROM ejecuciones LIKE 'archivo_log';
```

Si las tres devuelven una fila, está aplicada.

---

## 11. Datos que viven en disco

No todo está en MySQL. Estas columnas apuntan a archivos, o los describen:

| Columna | Archivo en disco | Quién lo escribe |
|---|---|---|
| `estrategias.archivo_rman` | `<ruta_scripts>\EST###.rma` | BackupGuard, al aprobar y antes de cada ejecución |
| `ejecuciones.archivo_log` | `<destino>\EST###_<fecha>_<hora>.log` (+ copia `.rma` con el mismo nombre) | RMAN (`log=`) y BackupGuard (la copia) |
| `ejecuciones.ubicacion`, `archivos_generados`, `tamano_bytes` | Piezas `EST###_<BD>_<fecha>_<set>_<pieza>.BKP` / `.SPFILE` / `.CTL` en `<destino>` | RMAN |
| `bases_datos.modo_archivado` | Archived logs en el destino de Oracle (`C:\BackupGuard_RMAN\arch`) | Oracle |

Las rutas y la estructura de carpetas están explicadas en [`manual-modo-real.md`](manual-modo-real.md), secciones 11 y 12.

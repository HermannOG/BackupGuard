# Qué se puede respaldar con BackupGuard: base completa, tablespaces y tablas

Este documento responde una pregunta que surgió en la defensa: **¿la herramienta solo hace respaldos completos, o se puede elegir qué respaldar, por ejemplo una tabla específica?** Cada respuesta indica el archivo donde se puede comprobar.

**Resumen:** se puede elegir el alcance, pero a nivel de **base completa, tablespaces o datafiles**. No se pueden elegir tablas sueltas, y eso es por diseño: RMAN hace respaldos físicos y su unidad mínima es el datafile. Para proteger una tabla crítica, la buena práctica es ubicarla en su propio tablespace y crear para ese tablespace una estrategia con más frecuencia.

---

## 1. Qué permite elegir el formulario

En `estrategia-form.php`, bloque **"Qué respaldar"**, el campo *Alcance* tiene tres opciones. `includes/RmanBuilder.php` (métodos `lineasBackup()` y `objetoBackup()`) las traduce así:

| Alcance | Cuándo usarlo | Script RMAN generado |
|---|---|---|
| **Base completa** (opción por defecto) | Proteger todo: es la base de cualquier plan de recuperación | `BACKUP ... DATABASE` |
| **Tablespaces específicos** | Proteger solo los datos de una aplicación o departamento | `BACKUP ... TABLESPACE FARMACIA, VENTAS` |
| **Datafiles específicos** | Casos puntuales, por número o ruta de archivo | `BACKUP ... DATAFILE 4, 7` |

Los tablespaces o datafiles se escriben uno por línea y se guardan en la tabla `estrategia_objetos`. Si el alcance no es "base completa" y no se indica ningún objeto, la validación lo bloquea.

Además del alcance, la estrategia define:

- **Tipo de respaldo:** completo, incremental nivel 0 o incremental nivel 1 (diferencial o acumulativo).
- **Qué se incluye:** control file, spfile y archived redo logs (con la opción de borrarlos después de respaldarlos).
- **Opciones:** compresión, paralelismo, retención y verificación (`RESTORE ... VALIDATE`).

---

## 2. Por qué no se pueden elegir tablas

| | Respaldo **físico** | Respaldo **lógico** |
|---|---|---|
| Herramienta de Oracle | **RMAN** | **Data Pump** (`expdp` / `impdp`) |
| Qué copia | Bloques de los datafiles | Filas y definiciones de objetos |
| Unidad mínima | Datafile o tablespace | Tabla, esquema, etc. |
| Ejemplo | `BACKUP TABLESPACE FARMACIA;` | `expdp ... TABLES=T3` |

RMAN no sabe qué es una tabla: trabaja con los archivos físicos de la base. El enunciado pide usar **RMAN como motor de ejecución**, así que la herramienta ofrece los niveles que RMAN maneja. Respaldar una sola tabla es un respaldo lógico, y eso le corresponde a Data Pump.

---

## 3. Cómo proteger una tabla crítica con BackupGuard

**Ejemplo:** la tabla `T3` guarda los datos del departamento de farmacia, y perderlos sería grave.

### Paso 1: darle a la tabla su propio tablespace (en Oracle, lo hace el DBA)

```sql
CREATE TABLESPACE FARMACIA
  DATAFILE 'farmacia01.dbf' SIZE 100M AUTOEXTEND ON;

ALTER TABLE T3 MOVE TABLESPACE FARMACIA;

-- Mover la tabla deja sus índices inválidos: hay que reconstruirlos.
ALTER INDEX <indice_de_t3> REBUILD TABLESPACE FARMACIA;
```

### Paso 2: crear una estrategia exclusiva para farmacia

| Campo | Valor |
|---|---|
| Nombre | Farmacia – datos críticos |
| Alcance | Tablespaces específicos → `XEPDB1:FARMACIA` (la tabla vive en la PDB; ver sección 4) |
| Prioridad | Alta |
| Justificación | Datos del departamento de farmacia; su pérdida afecta la atención. |
| Tipo | Completo (o un incremental nivel 0 semanal más un nivel 1 diario) |
| Frecuencia | Cada pocas horas (frecuencia "por horas") |
| Archived logs | Incluidos, para poder recuperar hasta el último cambio |

El script que genera la herramienta queda así:

```
BACKUP AS BACKUPSET TABLESPACE XEPDB1:FARMACIA TAG '...' FORMAT '...' PLUS ARCHIVELOG;
```

### Paso 3: mantener además el respaldo completo de la base

Una estrategia **full semanal** de toda la base (como `XINS`, EST010) sigue siendo necesaria. Así la tabla crítica tiene protección **más frecuente** que el resto, y el resto de la base no queda desprotegido.

### Recuperación

Si se dañan los datafiles de `FARMACIA`, se restaura solo ese tablespace **con la base abierta**: los demás departamentos siguen trabajando. Los pasos son:

```
-- 1. En SQL, dentro de la PDB: sacar de línea solo ese tablespace
ALTER SESSION SET CONTAINER = XEPDB1;
ALTER TABLESPACE FARMACIA OFFLINE IMMEDIATE;

-- 2. En RMAN: restaurar y recuperar
RESTORE TABLESPACE XEPDB1:FARMACIA;
RECOVER TABLESPACE XEPDB1:FARMACIA;

-- 3. En SQL, dentro de la PDB: ponerlo en línea otra vez
ALTER TABLESPACE FARMACIA ONLINE;
```

La herramienta muestra este plan en la página de cada estrategia (`RmanBuilder::planRecuperacion()`); ver sección 4.

Esto requiere que la base esté en modo **ARCHIVELOG**. La base XE del proyecto lo está, y la herramienta lo detecta leyendo `v$database`.

> **Dato adicional (verificar antes de afirmarlo):** desde Oracle 12c, RMAN tiene `RECOVER TABLE ... UNTIL TIME`, que recupera una sola tabla a partir de un respaldo, sin restaurar todo el tablespace. Requiere ARCHIVELOG. Falta confirmar si la edición XE 21c lo incluye.

---

## 4. Ejemplos: qué escribir en el formulario y qué script se genera

Los scripts de esta sección **los generó la propia herramienta** (`RmanBuilder::construir()`) contra la base XE del proyecto, con destino `C:\BackupGuard_RMAN\XE`. Se omite el encabezado de comentarios que agrega a cada script.

### Antes de empezar: cómo se escriben los objetos

En el formulario, el campo **"Tablespaces a respaldar" / "Datafiles a respaldar"** recibe **un objeto por línea**:

| Alcance | Qué se escribe | Ejemplos |
|---|---|---|
| Tablespaces | El nombre del tablespace. Las minúsculas se pasan a mayúsculas automáticamente. | `USERS` |
| Tablespaces de una PDB | `PDB:TABLESPACE` | `XEPDB1:FARMACIA` |
| Datafiles | El **número** del datafile o su **ruta completa** | `7` · `C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\USERS01.DBF` |

**Por qué hace falta el prefijo de la PDB:** Oracle XE 21c es una base *multitenant*. RMAN se conecta al contenedor raíz (`CDB$ROOT`), pero las tablas de una aplicación normalmente viven en la base conectable `XEPDB1`. Si se escribe solo `USERS`, RMAN respalda el `USERS` del contenedor raíz; para el `USERS` de la aplicación hay que escribir `XEPDB1:USERS`.

Para consultar qué nombres existen:

```sql
-- Tablespaces de la PDB (conectado a XEPDB1)
SELECT tablespace_name FROM dba_tablespaces;

-- Número y ruta de los datafiles, con su tablespace
SELECT file_id, file_name, tablespace_name FROM dba_data_files;

-- ¿En qué tablespace está la tabla T3?
SELECT owner, table_name, tablespace_name FROM dba_tables WHERE table_name = 'T3';
```

En la base XE del proyecto, el contenedor raíz tiene estos datafiles:

| N.º | Tablespace | Archivo |
|---|---|---|
| 1 | SYSTEM | `C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\SYSTEM01.DBF` |
| 3 | SYSAUX | `C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\SYSAUX01.DBF` |
| 4 | UNDOTBS1 | `C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\UNDOTBS01.DBF` |
| 7 | USERS | `C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\USERS01.DBF` |

### Ejemplo A: un tablespace

**Formulario:** Alcance = *Tablespaces específicos* · Objetos = `USERS` · Tipo = Completo

```
RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;

  BACKUP AS BACKUPSET TABLESPACE USERS TAG 'EST011_FULL' FORMAT 'C:\BackupGuard_RMAN\XE\EST011_%d_%T_%s_%p.bkp';

  RELEASE CHANNEL ch1;
}

# Verificación: comprueba que lo respaldado sirve para restaurar.
RESTORE TABLESPACE USERS VALIDATE;

# Reporte para la evidencia de ejecución.
LIST BACKUP SUMMARY;
REPORT NEED BACKUP;

EXIT;
```

### Ejemplo B: el tablespace crítico de farmacia (en la PDB)

**Formulario:** Alcance = *Tablespaces específicos* · Objetos = `XEPDB1:FARMACIA` · Tipo = Completo · Comprimido = sí · Archived logs = sí · Retención = 14 días

```
RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;

  BACKUP AS COMPRESSED BACKUPSET TABLESPACE XEPDB1:FARMACIA TAG 'EST012_FULL' FORMAT 'C:\BackupGuard_RMAN\XE\EST012_%d_%T_%s_%p.bkp' PLUS ARCHIVELOG;

  RELEASE CHANNEL ch1;
}

# Verificación: comprueba que lo respaldado sirve para restaurar.
RESTORE TABLESPACE XEPDB1:FARMACIA VALIDATE;

# Limpieza: solo se llega aquí si la verificación pasó.
CROSSCHECK BACKUP;
DELETE NOPROMPT EXPIRED BACKUP;
DELETE NOPROMPT OBSOLETE RECOVERY WINDOW OF 14 DAYS;

# Reporte para la evidencia de ejecución.
LIST BACKUP SUMMARY;
REPORT NEED BACKUP RECOVERY WINDOW OF 14 DAYS;

EXIT;
```

Qué aporta cada opción:

- `AS COMPRESSED BACKUPSET`: el respaldo ocupa menos espacio.
- `PLUS ARCHIVELOG`: respalda también los archived logs, para recuperar hasta el último cambio.
- `RECOVERY WINDOW OF 14 DAYS`: la retención; borra los respaldos que ya no hacen falta para volver 14 días atrás.

> **Cuidado con `RECOVERY WINDOW`:** la política de retención se aplica a todos los respaldos de la base, no solo a los de esta estrategia. Si otra estrategia de la misma base necesita una ventana más larga, conviene darles a todas la misma retención.

### Ejemplo C: incremental del tablespace de farmacia

Un esquema típico para una tabla crítica es un nivel 0 semanal y un nivel 1 diario, o cada pocas horas.

**Formulario:** Alcance = *Tablespaces específicos* · Objetos = `XEPDB1:FARMACIA` · Tipo = Incremental nivel 1 · Modalidad = Diferencial · Archived logs = sí

```
RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;

  BACKUP INCREMENTAL LEVEL 1 AS BACKUPSET TABLESPACE XEPDB1:FARMACIA TAG 'EST014_INC1D' FORMAT 'C:\BackupGuard_RMAN\XE\EST014_%d_%T_%s_%p.bkp' PLUS ARCHIVELOG;

  RELEASE CHANNEL ch1;
}

RESTORE TABLESPACE XEPDB1:FARMACIA VALIDATE;

LIST BACKUP SUMMARY;
REPORT NEED BACKUP;

EXIT;
```

La herramienta advierte que un nivel 1 **no alcanza por sí solo** para restaurar: necesita un nivel 0 del mismo tablespace, de otra estrategia. Si la modalidad es *Acumulativo*, el comando agrega `CUMULATIVE` después de `LEVEL 1`.

### Ejemplo D: datafiles por número o por ruta

**Formulario:** Alcance = *Datafiles específicos* · Objetos = `7` (o la ruta completa) · Tipo = Completo

```
RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;

  BACKUP AS BACKUPSET DATAFILE 7 TAG 'EST013_FULL' FORMAT 'C:\BackupGuard_RMAN\XE\EST013_%d_%T_%s_%p.bkp';

  RELEASE CHANNEL ch1;
}

RESTORE DATAFILE 7 VALIDATE;

LIST BACKUP SUMMARY;
REPORT NEED BACKUP;

EXIT;
```

Si en lugar del número se escribe la ruta, la herramienta la pone entre comillas simples. Se pueden combinar varios datafiles, uno por línea:

```
BACKUP AS BACKUPSET DATAFILE 7, 'C:\APP\ISAAC\PRODUCT\21C\ORADATA\XE\USERS01.DBF' TAG ...
```

### Plan de recuperación que muestra la herramienta

Para el ejemplo A (tablespace `USERS`), la página de la estrategia muestra este plan. **No lo ejecuta**: es una guía para el DBA.

```
# Paso previo, sin riesgo: muestra qué respaldos usaría RMAN, sin restaurar nada.
RESTORE TABLESPACE USERS PREVIEW;

# La base sigue abierta: solo se restauran los tablespaces afectados.
ALTER TABLESPACE USERS OFFLINE IMMEDIATE;

RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;
  RESTORE TABLESPACE USERS;
  RECOVER TABLESPACE USERS;
  RELEASE CHANNEL ch1;
}

ALTER TABLESPACE USERS ONLINE;
```

Para datafiles, el plan es el mismo, pero usa `ALTER DATABASE DATAFILE 7 OFFLINE` / `ONLINE` en lugar de `ALTER TABLESPACE`.

> **Limitación conocida (tablespaces de una PDB):** para `XEPDB1:FARMACIA`, el plan escribe `ALTER TABLESPACE XEPDB1:FARMACIA OFFLINE IMMEDIATE;`, y esa sintaxis **no es válida en SQL**: el prefijo `XEPDB1:` solo lo entiende RMAN. Las líneas `OFFLINE` y `ONLINE` se tienen que ejecutar dentro de la PDB:
>
> ```sql
> ALTER SESSION SET CONTAINER = XEPDB1;
> ALTER TABLESPACE FARMACIA OFFLINE IMMEDIATE;
> -- ... RESTORE / RECOVER TABLESPACE XEPDB1:FARMACIA desde RMAN ...
> ALTER TABLESPACE FARMACIA ONLINE;
> ```
>
> Los comandos `RESTORE` y `RECOVER` con el prefijo sí son correctos.

---

## 5. Respuesta corta para la defensa

> "La herramienta permite elegir el alcance del respaldo: base completa, que viene por defecto, tablespaces específicos o datafiles específicos. No baja a nivel de tabla porque RMAN hace respaldos físicos y su unidad mínima es el datafile; respaldar una tabla es un respaldo lógico y le corresponde a Data Pump.
>
> Para una tabla crítica, como la de farmacia, la buena práctica es ubicarla en su propio tablespace y crear una estrategia con alcance 'tablespaces', prioridad alta, más frecuencia y archived logs incluidos, además del full semanal de toda la base. Si ese tablespace se daña, se restaura solo él, con la base abierta."

---

## 6. Dónde comprobarlo en el código

| Qué | Archivo |
|---|---|
| Opciones de alcance en el formulario | `estrategia-form.php` (bloque "Qué respaldar") |
| Traducción del alcance a RMAN | `includes/RmanBuilder.php` → `objetoBackup()` |
| Validación (alcance sin objetos) | `includes/RmanBuilder.php` → `validar()` |
| Plan de recuperación por tablespace | `includes/RmanBuilder.php` → `planRecuperacion()` |
| Tablas donde se guarda | `estrategias.alcance` y `estrategia_objetos` (ver `diccionario-de-datos.md`) |

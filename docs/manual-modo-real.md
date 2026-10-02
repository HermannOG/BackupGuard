# Manual: BackupGuard en modo real (Oracle + RMAN de verdad)

Este manual explica cómo dejar BackupGuard funcionando **contra Oracle real**, sin simulación, en tu propia computadora. Recoge todo lo que se hizo y se probó el 2026-10-01 en la máquina de Isaac, incluidos los problemas que aparecieron y cómo se resolvieron.

Al terminar vas a poder:

- crear una estrategia en la web y que el **creador** genere su archivo `EST###.rma`;
- ver la estrategia en el **catálogo** con su día y su hora;
- dejar el **ejecutor** corriendo para que, a la hora programada, lance RMAN solo y deje el backup y su log en una carpeta.

**Tiempo estimado:** 30 a 45 minutos la primera vez, más unos 20 segundos por cada backup de prueba.

> **Probado con:** Windows 11, Oracle Database 21c Express Edition (XE), PHP 8.3, MySQL 8 (WampServer). Con XAMPP y MariaDB funciona igual; solo cambian las rutas de `php.exe` y `mysql.exe`.

---

## Contenido

1. [Cómo funciona, en un minuto](#1-cómo-funciona-en-un-minuto)
2. [Antes de empezar](#2-antes-de-empezar)
3. [Paso 1 — Actualizar el proyecto y la base MySQL](#paso-1--actualizar-el-proyecto-y-la-base-mysql)
4. [Paso 2 — Crear las carpetas de respaldo](#paso-2--crear-las-carpetas-de-respaldo)
5. [Paso 3 — Poner Oracle en ARCHIVELOG](#paso-3--poner-oracle-en-archivelog)
6. [Paso 4 — Crear el usuario de respaldos](#paso-4--crear-el-usuario-de-respaldos)
7. [Paso 5 — Configurar `config.php`](#paso-5--configurar-configphp)
8. [Paso 6 — Registrar la base en BackupGuard](#paso-6--registrar-la-base-en-backupguard)
9. [Paso 7 — Primer backup manual](#paso-7--primer-backup-manual)
10. [Paso 8 — Backup automático con el ejecutor](#paso-8--backup-automático-con-el-ejecutor)
11. [Qué archivos se crean y dónde](#11-qué-archivos-se-crean-y-dónde)
12. [La carpeta `storage/` del proyecto](#12-la-carpeta-storage-del-proyecto)
13. [Dónde queda la evidencia](#13-dónde-queda-la-evidencia)
14. [Limpieza y mantenimiento](#14-limpieza-y-mantenimiento)
15. [Qué se cambió en el código para el modo real](#15-qué-se-cambió-en-el-código-para-el-modo-real)
16. [Problemas comunes](#16-problemas-comunes)
17. [Lista de verificación final](#17-lista-de-verificación-final)

Para el detalle de cada tabla y columna de MySQL, ver [`diccionario-de-datos.md`](diccionario-de-datos.md).

---

## 1. Cómo funciona, en un minuto

El profesor lo planteó en tres piezas (pizarra de la hora de consulta):

```
 ┌──────────────┐   aprueba   ┌──────────────────────────┐   lee cada 30 s   ┌──────────────┐
 │   CREADOR    │ ──────────▶ │   CATÁLOGO (MySQL)        │ ◀──────────────── │   EJECUTOR   │
 │ (formulario  │  escribe    │ código · día · hora · .rma│                   │ runner.php   │
 │  web)        │  EST###.rma │                           │  ¿es el día?      │  --loop      │
 └──────────────┘             └──────────────────────────┘  ¿es la hora?     └──────┬───────┘
                                                              sí → ejecuta          │
                                                                                     ▼
                                         rman target ... cmdfile=EST###.rma log=EST###_….log
                                                                                     │
                                                                                     ▼
                                        Carpeta destino: piezas .BKP + .SPFILE + .log + copia .rma
```

- **MySQL** guarda el catálogo de estrategias y toda la evidencia. Está **fuera** de Oracle a propósito: el profesor dijo que el catálogo *"no debería ser un archivo dentro de la base de datos; tiene que ser exterior"*. Si Oracle se cae, el catálogo y la evidencia siguen disponibles.
- **Oracle** es solo la base que se respalda.
- **RMAN** es el programa de Oracle que hace el backup. BackupGuard nunca copia archivos por su cuenta: escribe el script y llama a `rman.exe`.

---

## 2. Antes de empezar

Revisá que tengas todo esto. Si falta algo, la guía [`instalacion-local.md`](instalacion-local.md) explica cómo instalarlo.

| Requisito | Cómo comprobarlo | Qué debe salir |
|---|---|---|
| Oracle XE instalado y encendido | `Get-Service Oracle*` en PowerShell | `OracleServiceXE` y `OracleOraDB21Home1TNSListener` en **Running** |
| `rman` y `sqlplus` accesibles | `where rman` en CMD | Una ruta como `C:\app\<usuario>\product\21c\dbhomeXE\bin\rman.exe` |
| Tu usuario de Windows es administrador de Oracle | `sqlplus / as sysdba` | Entra sin pedir contraseña (`Conectado a: Oracle Database 21c…`) |
| PHP 8 con las extensiones necesarias | `php -m` | Deben aparecer `pdo_mysql`, `openssl`, `mbstring` y **`oci8`** |
| MySQL o MariaDB encendido | Panel de WAMP o XAMPP | Servicio en verde |
| Espacio en disco | Explorador de archivos | **Al menos 10 GB libres** (cada full backup de XE ocupa unos 3 GB) |

> **Sin `oci8`** la web no puede leer el modo de archivado de Oracle (botón *Verificar*). RMAN igual funcionaría, pero las validaciones y alertas quedarían ciegas. Ver *Parte 2, paso 3* de [`instalacion-local.md`](instalacion-local.md).

---

## Paso 1 — Actualizar el proyecto y la base MySQL

1. Bajá los últimos cambios del repositorio (GitHub Desktop → **Fetch origin** → **Pull origin**, o `git pull`).
2. Revisá si tu base `backupguard` ya tiene las tablas nuevas. En phpMyAdmin, pestaña **SQL**:
   ```sql
   SHOW TABLES LIKE 'estrategia_horarios';
   ```
3. Según el resultado:

| Situación | Qué hacer |
|---|---|
| **No tenés** base `backupguard` | Crearla vacía (`utf8mb4`) e importar **solo** `database/schema.sql`. Ya trae todo. |
| La tenés y la consulta **no devuelve nada** | Importar **una vez** `database/migracion-catalogo.sql` (pestaña **Importar**). |
| La consulta devuelve `estrategia_horarios` | No hacer nada: ya está al día. |

> ⚠️ **No importes la migración sobre un `schema.sql` recién creado.** Fallaría con *"Duplicate column name"*, porque esas columnas ya vienen en el esquema.

Qué agrega la migración: la tabla `estrategia_horarios` (catálogo día-hora), la columna `estrategias.archivo_rman` (ruta del `.rma`) y la columna `ejecuciones.archivo_log` (ruta del log). El detalle está en el [diccionario de datos](diccionario-de-datos.md#10-migración-migracion-catalogosql).

---

## Paso 2 — Crear las carpetas de respaldo

Todo lo que produce el modo real queda bajo una sola carpeta, fuera del proyecto:

```
C:\BackupGuard_RMAN\
├── arch\      ← Oracle guarda aquí los archived redo logs (paso 3)
├── scripts\   ← BackupGuard guarda aquí los EST###.rma aprobados
└── XE\        ← destino de los backups de la base XE
```

Creálas desde CMD:

```
mkdir C:\BackupGuard_RMAN\arch C:\BackupGuard_RMAN\scripts C:\BackupGuard_RMAN\XE
```

Hay que crear `arch` **antes** del paso 3: Oracle se niega a usar una carpeta que no existe (`ORA-16032`). `scripts` y `XE` la aplicación las crea sola si faltan, pero conviene tenerlas desde el principio.

> ⚠️ **Nunca borres la carpeta `arch`.** Desde el paso 3, Oracle escribe ahí cada vez que llena un redo log. Si la carpeta desaparece, Oracle no puede archivar y la base **se congela** (`ORA-00257`) hasta que la vuelvas a crear. Su contenido se limpia con RMAN (sección 14), nunca a mano.

---

## Paso 3 — Poner Oracle en ARCHIVELOG

### Por qué

Oracle XE viene instalado en **NOARCHIVELOG**. En ese modo RMAN **no puede** respaldar la base mientras está abierta: falla con `ORA-19602`. Fue lo primero que hubo que cambiar. En **ARCHIVELOG**, Oracle guarda cada redo log lleno en `C:\BackupGuard_RMAN\arch`, lo que permite:

- backups con la base abierta (en caliente), que es lo que hace el ejecutor;
- recuperar hasta un punto exacto en el tiempo.

### Comprobar el modo actual

```
sqlplus / as sysdba
```

```sql
SELECT log_mode FROM v$database;
```

Si dice `ARCHIVELOG`, saltá al paso 4. Si dice `NOARCHIVELOG`, seguí.

### Cambiarlo

> Esto **reinicia la base**: durante uno o dos minutos Oracle no responde. Cerrá antes SQL Developer o cualquier otra conexión.

Dentro de la misma sesión de `sqlplus / as sysdba`, línea por línea:

```sql
ALTER SYSTEM SET log_archive_dest_1='LOCATION=C:\BackupGuard_RMAN\arch' SCOPE=BOTH;
SHUTDOWN IMMEDIATE
STARTUP MOUNT
ALTER DATABASE ARCHIVELOG;
ALTER DATABASE OPEN;
ALTER PLUGGABLE DATABASE ALL OPEN;
```

| Línea | Qué hace |
|---|---|
| `ALTER SYSTEM SET log_archive_dest_1=…` | Le dice a Oracle dónde guardar los archived logs. `SCOPE=BOTH` lo deja también para los próximos reinicios. |
| `SHUTDOWN IMMEDIATE` | Cierra la base de forma ordenada. |
| `STARTUP MOUNT` | La levanta sin abrirla: el modo de archivado solo se cambia en este estado. |
| `ALTER DATABASE ARCHIVELOG` | El cambio en sí. |
| `ALTER DATABASE OPEN` | Abre la base para uso normal. |
| `ALTER PLUGGABLE DATABASE ALL OPEN` | Abre también las PDBs (por ejemplo `XEPDB1`), que no se abren solas tras el reinicio. |

### Verificar

```sql
SELECT log_mode FROM v$database;
ARCHIVE LOG LIST
```

Debe salir:

```
LOG_MODE
------------
ARCHIVELOG

Modo log de la base de datos          Modo de Archivado
Archivado automático                  Activado
Destino del archivo                   C:\BackupGuard_RMAN\arch
```

> **¿Por qué en el CDB y no en una PDB?** El modo de archivado es del contenedor completo (`XE`). Todas las PDBs comparten el mismo modo; no se puede tener una en ARCHIVELOG y otra en NOARCHIVELOG dentro del mismo XE (ver [`manual-oracle-xuna-xccss.md`](manual-oracle-xuna-xccss.md), sección 1).

---

## Paso 4 — Crear el usuario de respaldos

### Por qué un usuario propio

BackupGuard guarda la contraseña de la base (cifrada) y la usa para lanzar RMAN. No conviene que sea la de `SYS`. Se crea un usuario con lo justo:

| Privilegio | Para qué |
|---|---|
| `SYSBACKUP` | Privilegio administrativo de Oracle pensado solo para respaldos: RMAN puede hacer backups y validaciones, pero no administrar usuarios ni datos. |
| `CREATE SESSION` | Poder conectarse. |
| `SELECT_CATALOG_ROLE` | Leer el diccionario (`v$database`, `dba_data_files`…): lo usa el botón **Verificar** de la web. |

Es un **usuario común** (prefijo `C##` y `CONTAINER=ALL`) porque se conecta al CDB `XE`, que es el que se respalda completo. Si RMAN se conecta a una PDB, Oracle no permite `CONFIGURE` (lo usa la retención) ni respaldar archived logs.

### Crearlo

En `sqlplus / as sysdba`, cambiando `<clave>` por la contraseña que elijas:

```sql
CREATE USER c##bgbackup IDENTIFIED BY "<clave>" CONTAINER=ALL;
GRANT CREATE SESSION      TO c##bgbackup CONTAINER=ALL;
GRANT SYSBACKUP           TO c##bgbackup CONTAINER=ALL;
GRANT SELECT_CATALOG_ROLE TO c##bgbackup CONTAINER=ALL;
```

> No subas la contraseña a git ni la pongas en ningún archivo del proyecto. Solo se escribe en la web al registrar la base (paso 6), y BackupGuard la guarda cifrada.

### Verificar

```sql
SELECT username, sysbackup FROM v$pwfile_users WHERE username = 'C##BGBACKUP';
```

Debe devolver `C##BGBACKUP  TRUE`. Salí con `exit`.

Probá que RMAN conecta **exactamente como lo va a hacer BackupGuard**, desde **CMD** (no PowerShell):

```
rman target "'c##bgbackup/<clave>@localhost:1521/XE AS SYSBACKUP'"
```

Debe decir `conectado a la base de datos de destino: XE (DBID=…)`. Salí con `exit`.

> Fijate en las comillas: **dobles por fuera y simples por dentro**. Sin las simples, RMAN rechaza el `AS SYSBACKUP` con `RMAN-01009`. Fue uno de los errores que hubo que corregir en el código (sección 15).

---

## Paso 5 — Configurar `config.php`

`includes/config.php` es **personal de cada máquina** y no se sube a git. Si no lo tenés, copiá `includes/config.example.php` con ese nombre. Revisá estas claves:

| Clave | Valor para modo real | Explicación |
|---|---|---|
| `host`, `port`, `dbname`, `user`, `password` | Los de **tu** MySQL | Con XAMPP recién instalado: `root` y contraseña vacía. Con WAMP y MySQL 8 suele haber contraseña. |
| `encryption_key` | Una frase larga, solo tuya | Cifra las contraseñas de Oracle guardadas en MySQL. **Si la cambiás después, las contraseñas guardadas dejan de servir** y hay que volver a registrar las bases. |
| `rman_bin` | La ruta que dio `where rman` | Ej.: `'C:\\app\\<usuario>\\product\\21c\\dbhomeXE\\bin\\rman.exe'`. Ruta completa, por si PHP no hereda el `PATH`. |
| `oracle_tns_admin` | `null` | Solo hace falta si registrás bases con alias TNS. Este manual usa host, puerto y servicio. |
| `ruta_trabajo` | Dejar el valor por defecto | Carpeta `storage/` del proyecto (sección 12). |
| `ruta_scripts` | `'C:\\BackupGuard_RMAN\\scripts'` | Dónde se guardan los `EST###.rma`. Si queda en `null`, se usa `storage/rman`. |
| `modo_simulacion` | **`false`** | `true` = no llama a RMAN e inventa la salida. `false` = RMAN real. |
| `zona_horaria` | `'America/Costa_Rica'` | PHP y MySQL usan la misma, para que el ejecutor no se adelante ni se atrase. |

Si tu `config.php` es viejo y no tiene `ruta_scripts`, agregá esta línea debajo de `ruta_trabajo`:

```php
'ruta_scripts' => 'C:\\BackupGuard_RMAN\\scripts',
```

---

## Paso 6 — Registrar la base en BackupGuard

1. Arrancá la web con `iniciar.bat` y entrá a `http://localhost:8080`. Si es tu primera vez, creá el usuario administrador en `http://localhost:8080/crear-admin.php`.
2. Andá a **Bases de datos** y registrá:

| Campo | Valor |
|---|---|
| Nombre | `XE` |
| Ambiente | `pruebas` |
| Descripción | `Oracle XE 21c local (CDB completo)` |
| Alias TNS | **vacío** |
| Host | `localhost` |
| Puerto | `1521` |
| Service name | `XE` (el CDB, **no** `XEPDB1`) |
| Usuario | `c##bgbackup` |
| Contraseña | la del paso 4 |
| Casilla **SYSDBA** | **DESMARCADA** |

> **La casilla SYSDBA debe ir desmarcada.** Desmarcada significa que BackupGuard se conecta `AS SYSBACKUP`, que es el privilegio que tiene `c##bgbackup`. Marcada intentaría `AS SYSDBA` y fallaría con `ORA-01031`. (La guía vieja [`instalacion-local.md`](instalacion-local.md) decía lo contrario: hacé caso a esta.)

3. Presioná **Verificar** en la fila de `XE`. La columna *Archivado* debe pasar a **ARCHIVELOG** y deben aparecer los tablespaces (`SYSAUX`, `SYSTEM`, `TEMP`, `UNDOTBS1`, `USERS`).
4. **Desactivá las estrategias viejas.** Si tu base MySQL trae estrategias de la simulación (XUNA, XCCSS) u otras que apuntan a bases que no existen en tu Oracle, entrá a cada una y presioná **Desactivar**. Si no, el ejecutor va a intentar correrlas en modo real y van a fallar.

---

## Paso 7 — Primer backup manual

Antes de automatizar, comprobá que un backup funciona apretando un botón.

1. **Estrategias → Nueva estrategia.**
   > ⚠️ **Elegí la base primero, antes de escribir nada.** Al cambiar el selector de base, el formulario se recarga y, si ya tenía nombre, guarda la estrategia a medias.
2. Llenala así:

| Sección | Campo | Valor recomendado para la prueba |
|---|---|---|
| General | Base de datos | `XE` |
| | Nombre | `Prueba manual - Full` |
| | Estado | Inactiva (es manual) |
| Qué | Alcance | Base de datos completa |
| | Control file / SPFILE | ✅ / ✅ |
| | Archived redo logs | sin marcar |
| Cómo | Tipo | **Completo** |
| | Canales | `1` |
| | Retención | **vacía** (si ponés días, el script incluye `DELETE OBSOLETE` y borra backups viejos) |
| | Compresión | sin marcar (más rápido) |
| | Verificar el respaldo | ✅ |
| Destino | Carpeta | `C:\BackupGuard_RMAN\XE` |

3. **Guardar** → **Generar script** → leé el script → **Aprobar este script**.
   - Al aprobar aparece `C:\BackupGuard_RMAN\scripts\EST###.rma`. El número es el `id` de la estrategia en **tu** MySQL, así que puede no coincidir con el de tus compañeros.
4. **Ejecutar ahora** y esperá unos 20 segundos sin recargar la página.
5. Resultado esperado: **exitoso**, 5 archivos, unos 3 GB, sin la etiqueta *simulado*.

> El botón **Simular un fallo** no toca Oracle: crea una ejecución fallida de mentira, para mostrar cómo se ve un error en la evidencia. No lo uses para probar el modo real.

---

## Paso 8 — Backup automático con el ejecutor

### Arrancar el ejecutor

Doble clic en **`iniciar-ejecutor.bat`**, en la raíz del proyecto. Se abre una ventana que debe mostrar:

```
[2026-10-01 22:09:16] Ejecutor de BackupGuard iniciado. Revisa el catálogo cada 30 s. Ctrl+C para detener.
[2026-10-01 22:09:16] Modo: REAL (invoca RMAN)
[2026-10-01 22:09:16] Catálogo revisado — no hay estrategias activas y aprobadas en el catálogo
```

Si dice `Modo: SIMULACIÓN`, revisá `modo_simulacion` en `config.php` y reiniciá el ejecutor.

**Dejá esa ventana abierta.** Es el *"programa que está pegado, dando, dando"* del profesor: un ciclo infinito (`while true`) que cada 30 segundos lee el catálogo y pregunta *¿es el día? ¿es la hora?* Si la cerrás, nada se ejecuta solo.

Por debajo corre `php scripts/runner.php --loop --intervalo=30`. Alternativas al ciclo (no hacen falta para la prueba):

- `scripts\instalar-tarea-windows.bat` (como administrador) registra una tarea de Windows que hace **una pasada** cada 5 minutos;
- `php scripts/runner.php --dry-run` solo lista lo pendiente, sin ejecutar nada.

### Programar una prueba

1. **Nueva estrategia**, igual que en el paso 7, pero con:
   - **Estado: Activa**;
   - **Frecuencia: Semanal**;
   - **Fecha de inicio:** hoy;
   - en la tabla **Días y horas**, marcá **el día de hoy** y escribí la hora actual **+ 5 minutos** (por ejemplo `22:35`).
2. Guardar → **Generar script** → **Aprobar**.
3. En **Catálogo** aparece la fila con código, día, hora y archivo `.rma`. En la ventana del ejecutor: `Catálogo revisado — próxima: EST### … a las …`.
4. **No toques nada.** Entre 0 y 30 segundos después de la hora, el ejecutor muestra:

```
[2026-10-01 22:35:16] Es el día y la hora de EST007 "Prueba Isaac-Full" sobre XE — ejecutando RMAN...
[2026-10-01 22:35:34]   → EXITOSO (18s) — evidencia #19
[2026-10-01 22:35:34]   log: C:\BackupGuard_RMAN\XE\EST007_20261001_223516.log
```

5. **Al terminar, desactivá la estrategia** (botón **Desactivar**). Si no, vuelve a correr la semana siguiente a la misma hora.

### La hora general y las horas por día

En frecuencia semanal el formulario tiene dos lugares para la hora, y es fácil confundirse:

| Campo | Cuándo se usa |
|---|---|
| **Hora** (arriba, junto a la fecha) | Solo para los días marcados cuya casilla de horas quedó **vacía**. |
| **Horas** de cada día (tabla) | Si tiene algo, **reemplaza** a la hora general en ese día. Acepta varias separadas por coma: `13:00, 17:00`. |

Ejemplo real: con *Hora = 22:30* y el jueves marcado con *22:35*, el catálogo tiene **una sola** ejecución, jueves 22:35. Para que corra a las dos horas, la casilla del jueves debe decir `22:30, 22:35`.

El ejemplo de la pizarra (*L, J, S → 13, 15, 17*) se escribe: lunes `13:00`, jueves `15:00`, sábado `17:00`.

---

## 11. Qué archivos se crean y dónde

### Mapa completo

```
C:\BackupGuard_RMAN\
│
├── arch\                                   ← ORACLE (por ARCHIVELOG)
│   └── ARC0000000067_1213456789.0001        archived redo logs (formato ARC%S_%R.%T)
│
├── scripts\                                ← BACKUPGUARD, al aprobar un script
│   ├── EST005.rma
│   └── EST007.rma                           script RMAN aprobado de cada estrategia
│
└── XE\                                     ← RMAN + BACKUPGUARD, en cada ejecución
    ├── EST007_XE_20261001_25_1.BKP          ┐
    ├── EST007_XE_20261001_26_1.BKP          │ piezas del backup (datafiles
    ├── EST007_XE_20261001_27_1.BKP          │ y control file)
    ├── EST007_XE_20261001_28_1.BKP          ┘
    ├── EST007_XE_20261001_29_1.SPFILE        backup del SPFILE
    ├── EST007_20261001_223516.log            log de RMAN de esa ejecución
    └── EST007_20261001_223516.rma            copia del script que se ejecutó

C:\app\<usuario>\product\21c\dbhomeXE\database\
    └── C-3116517173-20261001-00             ← ORACLE: autobackup del control file
```

### Qué es cada archivo

| Archivo | Lo crea | Cuándo | Contenido | Tamaño típico (XE) |
|---|---|---|---|---|
| `EST###.rma` en `scripts\` | BackupGuard | Al **aprobar** el script. Se borra si la estrategia se edita o se regenera el script (pierde la aprobación). Se reescribe antes de cada ejecución. | El script RMAN en texto plano. **No** contiene contraseñas. Se puede abrir con el Bloc de notas y correr a mano. | ~1 KB |
| `EST###_<BD>_<AAAAMMDD>_<set>_<pieza>.BKP` | RMAN | En cada backup | Piezas binarias con los datafiles. La que incluye el control file es la de ~18 MB. | 4 piezas, ~3 GB en total |
| `EST###_…SPFILE` | RMAN | Si la estrategia incluye SPFILE | Copia del archivo de parámetros de la instancia. | ~112 KB |
| `EST###_…CTL` | RMAN | Solo si el alcance **no** es base completa y se incluye control file | Copia del control file aparte. | ~18 MB |
| `EST###_<AAAAMMDD>_<HHMMSS>.log` | RMAN | En cada ejecución | Todo lo que respondió RMAN: el script línea por línea, el progreso y la validación final. **Es la evidencia** que pidió el profesor. | ~10 KB |
| `EST###_<AAAAMMDD>_<HHMMSS>.rma` en el destino | BackupGuard | En cada ejecución | Copia exacta del script que se corrió esa vez. | ~1 KB |
| `ARC…` en `arch\` | Oracle | Cada vez que se llena un redo log | Cambios de la base, para recuperar hasta un punto en el tiempo. | Decenas de MB cada uno; crecen con el uso |
| `C-<DBID>-<fecha>-<n>` | Oracle | Al final de cada backup (*control file autobackup*, activado por defecto) | Copia del control file y del SPFILE. | ~18 MB |

**Cómo leer el nombre de una pieza:** `EST007_XE_20261001_25_1.BKP` es la estrategia **EST007**, la base **XE**, el **1 de octubre de 2026**, el backup set **25** y la pieza **1** de ese set. RMAN en Windows escribe los nombres en **MAYÚSCULAS** aunque el script diga `.bkp`.

**Archivos con prefijo `BG_`:** los backups de estrategias aprobadas **antes** del código de catálogo se llaman `BG_XE_…`. Si regenerás y volvés a aprobar el script, pasan a llamarse `EST###_…`.

**Si la estrategia no tiene destino** (vacío o `FRA`): RMAN usa la Fast Recovery Area. En XE local no está configurada, así que los archivos terminan en la carpeta `database` de Oracle, y el `.log` y la copia del `.rma` quedan en `storage/` del proyecto. **Usá siempre un destino explícito.**

---

## 12. La carpeta `storage/` del proyecto

`storage/` es la **carpeta de trabajo** de BackupGuard (`ruta_trabajo` en `config.php`). Está en `.gitignore`: **no se sube a git** y cada uno tiene la suya. Se crea sola la primera vez que hace falta.

| Contenido | Cuándo aparece | ¿Se puede borrar? |
|---|---|---|
| `EST###_<fecha>_<hora>.log` y `.rma` | Ejecuciones de estrategias **sin destino propio** (FRA) | Sí, pero la evidencia en la web dirá que el log *"ya no existe"*. El contenido del log sigue guardado en MySQL. |
| `rman\EST###.rma` | Solo si `ruta_scripts` está en `null` | No mientras la estrategia esté aprobada: es el archivo que corre el ejecutor. Si se borra, se regenera en la próxima ejecución. |
| `bg_<fecha>_<id>.log` | Ejecuciones hechas **antes** de los cambios del 2026-10-01 | Sí, sin problema: nada los referencia. |

En una instalación como la de este manual (con destino `C:\BackupGuard_RMAN\XE` y `ruta_scripts` configurada), `storage/` queda prácticamente vacía.

> Antes, `storage/` guardaba un `.rman` temporal que se **borraba** al terminar cada ejecución. Ya no: el script vive como `EST###.rma` en `scripts\` y se conserva, porque el profesor pidió poder abrirlo.

---

## 13. Dónde queda la evidencia

Cada ejecución deja evidencia en **dos lugares**, a propósito:

| Qué | En disco | En MySQL (tabla `ejecuciones`) |
|---|---|---|
| Log completo de RMAN | `EST###_…log` en el destino | `salida_rman` (texto completo) y `archivo_log` (ruta) |
| Script exacto que se corrió | `EST###_…rma` en el destino | `script_ejecutado` |
| Piezas del backup | `.BKP`, `.SPFILE` en el destino | `archivos_generados`, `tamano_bytes`, `ubicacion` |
| Resultado | — | `resultado` (`exitoso`, `advertencia`, `fallido`), `codigo_salida`, `mensaje_error` |
| Quién y cuándo | — | `origen` (`manual` o `programada`), `ejecutado_por` (tu usuario, o `ejecutor`), `inicio`, `fin`, `duracion_seg` |
| ¿Fue real? | — | `simulado` (0 = RMAN real) |

En la web se ve en **Historial → Ver evidencia**. Si alguien borra los archivos del disco, MySQL conserva el log y el script. Si se pierde MySQL, los archivos del disco siguen ahí.

**Cómo decide BackupGuard si salió bien** (no confía solo en que RMAN terminó):

| Condición | Resultado |
|---|---|
| RMAN terminó con código distinto de 0, o el log tiene errores graves (`RMAN-03009`, `ORA-19809`, `ORA-19804`…) | **fallido** |
| El log tiene otros `RMAN-`/`ORA-`, o la palabra *warning* | **advertencia** |
| RMAN dijo que terminó bien pero **no aparecieron archivos nuevos** en el destino | **advertencia** |
| Tardó más que la *ventana de respaldo* de la estrategia | **advertencia** |
| Ninguna de las anteriores | **exitoso** |

---

## 14. Limpieza y mantenimiento

### Borrar backups de prueba

Cada full backup de XE ocupa unos 3 GB. Para borrarlos **hacelo siempre con RMAN, no con el Explorador**: Oracle lleva un registro de cada backup en su control file, y si borrás los archivos a mano ese registro queda apuntando a archivos inexistentes.

En CMD:

```
rman target /
```

y dentro de RMAN (indicador `RMAN>`), línea por línea:

```
DELETE NOPROMPT BACKUP;
DELETE NOPROMPT ARCHIVELOG ALL;
exit
```

| Comando | Qué borra |
|---|---|
| `DELETE NOPROMPT BACKUP;` | Todas las piezas `.BKP` y `.SPFILE` y los autobackups `C-…` del control file, junto con su registro. |
| `DELETE NOPROMPT ARCHIVELOG ALL;` | Todos los archived logs de `arch\` (la carpeta queda, vacía). |

> Después de esto **no queda ningún backup** de la base hasta la próxima ejecución. En un entorno de práctica no importa; en producción nunca se haría así.

Los `.log` y `.rma` de BackupGuard no los toca RMAN. Se pueden dejar como evidencia o borrar a mano.

### Si ya borraste archivos a mano

```
rman target /
CROSSCHECK BACKUP;
DELETE NOPROMPT EXPIRED BACKUP;
exit
```

Y si borraste `arch`, volvé a crearla de inmediato: `mkdir C:\BackupGuard_RMAN\arch`.

### Comprobar que quedó limpio

```
rman target /
LIST BACKUP SUMMARY;
LIST ARCHIVELOG ALL;
exit
```

Las dos deben responder *"la especificación no coincide con ninguna…"*.

### Después de cada prueba

- **Desactivá** las estrategias de prueba (si no, vuelven a correr la semana siguiente).
- Cerrá la ventana del ejecutor con **Ctrl+C** si no lo vas a usar.

### Volver a NOARCHIVELOG (opcional)

Solo si necesitás mostrar la advertencia de NOARCHIVELOG en vivo. En `sqlplus / as sysdba`:

```sql
SHUTDOWN IMMEDIATE
STARTUP MOUNT
ALTER DATABASE NOARCHIVELOG;
ALTER DATABASE OPEN;
ALTER PLUGGABLE DATABASE ALL OPEN;
```

Después presioná **Verificar** en la web. Mientras esté así, **los backups en caliente fallan** (`ORA-19602`). Para volver, repetí el paso 3.

---

## 15. Qué se cambió en el código para el modo real

En modo simulación todo parecía funcionar, pero la primera ejecución real destapó **cinco errores** que la simulación escondía. Ya están corregidos; si alguno vuelve a aparecer es porque tenés código viejo (hacé pull).

| # | Síntoma | Causa | Arreglo |
|---|---|---|---|
| 1 | `RMAN-01009: … se ha encontrado "as"` | La conexión se pasaba como `usuario/clave@BD AS SYSDBA` sin comillas, y RMAN lee `AS` como otro argumento. | La cadena va entre comillas simples y usa `AS SYSBACKUP` si la casilla SYSDBA está desmarcada (`includes/oracle.php`). |
| 2 | `SQLSTATE[HY000]: 1366 Incorrect string value` al guardar | RMAN en Windows escribe en Windows-1252 (la "ó" de *conexión*) y MySQL espera UTF-8. | La salida se convierte a UTF-8 antes de guardarla (`includes/Ejecutor.php`). |
| 3 | `RMAN-02001: símbolo de puntuación "\" no reconocido` | La ruta del proyecto tiene espacios (`Bases II`) y RMAN no la acepta sin comillas. | `cmdfile=` y `log=` van entre comillas simples. |
| 4 | `RMAN-01009: … se ha encontrado "all"` y no se hace ningún backup | `VALIDATE BACKUPSET ALL` no es un comando válido, y RMAN revisa el script completo antes de empezar. | Se usa `RESTORE … VALIDATE` para los datos, el control file y el SPFILE (`includes/RmanBuilder.php`). |
| 5 | Resultado *advertencia: no se encontraron archivos* aunque el backup existe | RMAN escribe los nombres en MAYÚSCULAS y el conteo buscaba en minúsculas. | El conteo no distingue mayúsculas. |

Además se agregó lo que el profesor pidió en la hora de consulta (ver [`respuestas-hora-consulta.md`](respuestas-hora-consulta.md)):

| Pedido | Implementación |
|---|---|
| El creador produce un archivo RMAN plano (`EST001.rma`) | Al aprobar se escribe `EST###.rma` en `ruta_scripts`; el ejecutor corre ese archivo y ya no lo borra. |
| Catálogo con días y horas | Tabla `estrategia_horarios` y página **Catálogo**. |
| Un ejecutor que es un ciclo infinito | `runner.php --loop` / `iniciar-ejecutor.bat`. |
| El log tiene que quedar | `.log` y copia del `.rma` en la carpeta destino, junto al backup. |

---

## 16. Problemas comunes

| Síntoma | Causa | Solución |
|---|---|---|
| `ORA-19602: cannot backup or copy active file in NOARCHIVELOG mode` | La base sigue en NOARCHIVELOG. | Paso 3. |
| `ORA-16032` o *"no se puede iniciar el destino de archivado"* | La carpeta `arch` no existe al configurar `log_archive_dest_1`. | `mkdir C:\BackupGuard_RMAN\arch` y repetí el comando. |
| La base no responde; `ORA-00257: archiver error` | Se borró la carpeta `arch` o se llenó el disco. | Recrear la carpeta, liberar espacio y limpiar con RMAN (sección 14). |
| `ORA-01031: insufficient privileges` | Casilla SYSDBA marcada con `c##bgbackup`, o falta el `GRANT SYSBACKUP`. | Desmarcar la casilla (borrar la base y registrarla de nuevo) o repetir el paso 4. |
| `ORA-01017: invalid username/password` | Contraseña mal escrita al registrar la base. | Registrar la base de nuevo. |
| *"No se pudo descifrar la contraseña: la encryption_key cambió"* | Se cambió `encryption_key` o se importaron datos de otra máquina. | Registrar la base de nuevo con la clave actual. |
| `ORA-12541: TNS: no listener` | El listener está apagado. | Servicio `OracleOraDB21Home1TNSListener` → Iniciar, o `lsnrctl start`. |
| `ORA-12514: listener does not currently know of service` | Service name equivocado. | Usar `XE` (el CDB). |
| `sqlplus / as sysdba` pide contraseña o da `ORA-01031` | Tu usuario de Windows no está en el grupo `ORA_DBA`. | Usar la cuenta con la que se instaló Oracle. |
| `Table 'backupguard.estrategia_horarios' doesn't exist` | Falta la migración. | Paso 1. |
| `Duplicate column name 'archivo_rman'` al migrar | La migración ya estaba aplicada, o la base se creó con el `schema.sql` nuevo. | Nada que hacer: ya está al día. |
| El ejecutor dice `Modo: SIMULACIÓN` | `modo_simulacion` sigue en `true`. | Cambiarlo a `false` y reiniciar el ejecutor. |
| El ejecutor no ejecuta mi estrategia | No está **activa**, su script no está **aprobado**, o la hora quedó en el campo general y no en la tabla de días. | Revisar en **Catálogo** el día, la hora y la próxima ejecución. |
| Arranca unos segundos después de la hora | Normal: el ejecutor revisa cada 30 segundos. | — |
| La estrategia se guardó sola a medias | Se cambió la base después de escribir el nombre. | Elegir la base primero (paso 7). |
| `SP2-0042: comando desconocido "﻿"` al pasarle comandos a `sqlplus` desde PowerShell | PowerShell agrega un carácter invisible (BOM) al inicio. | Escribir los comandos dentro de `sqlplus` en vez de pasarlos con una tubería. |
| Las tildes del log se ven como `Ã¡` | Código viejo. | Hacer pull: el `.rma` ahora se escribe en Windows-1252. |

---

## 17. Lista de verificación final

Marcá cada punto. Si todos están ✅, tu máquina está lista para la prueba del profesor.

- [ ] `git pull` hecho y MySQL al día (`estrategia_horarios` existe).
- [ ] Carpetas `C:\BackupGuard_RMAN\arch`, `scripts` y `XE` creadas.
- [ ] `SELECT log_mode FROM v$database;` → `ARCHIVELOG`, con destino `C:\BackupGuard_RMAN\arch`.
- [ ] Usuario `c##bgbackup` con `SYSBACKUP`, y `rman target "'c##bgbackup/…@localhost:1521/XE AS SYSBACKUP'"` conecta.
- [ ] `config.php`: `rman_bin` con ruta completa, `ruta_scripts`, `modo_simulacion => false`.
- [ ] Base `XE` registrada con la casilla SYSDBA **desmarcada**, y **Verificar** muestra ARCHIVELOG.
- [ ] Estrategias viejas o de simulación desactivadas.
- [ ] Un backup con **Ejecutar ahora** salió **exitoso** (5 archivos, ~3 GB, sin "simulado").
- [ ] `iniciar-ejecutor.bat` muestra `Modo: REAL`.
- [ ] Una estrategia programada a +5 minutos corrió sola: origen **programada**, ejecutado por **ejecutor**.
- [ ] En la carpeta destino están las piezas, el `.log` y la copia del `.rma`.
- [ ] Estrategias de prueba desactivadas y backups limpiados con RMAN.

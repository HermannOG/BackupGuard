# BackupGuard frente a lo pedido en la hora de consulta

Este documento compara lo que explicó el profesor (transcripción `HoraConsultaAdmin` y las tres fotos de la pizarra) con lo que hace hoy el código del proyecto. Cada respuesta indica el archivo donde se puede comprobar.

**Resumen:** el proyecto ya tiene las tres piezas que pidió el profesor (creador, catálogo y ejecutor) y es capaz de ejecutar un full backup real con RMAN.

> **Actualización 2026-10-01:** las cinco diferencias de la sección 8 ya están resueltas y se ensayó la prueba completa. La estrategia EST006 se programó para dentro de 2 minutos y el ejecutor en ciclo la corrió solo, con resultado exitoso. En la carpeta destino dejó 5 piezas (3,09 GB), el `.log` y la copia del `.rma`. Cómo repetirlo: sección 10. Las secciones 2 a 6 describen el estado **antes** de los cambios; se conservan como análisis.

---

## 1. Qué espera ver el profesor en la prueba

Según la transcripción (min. 03:25 a 04:50 y 12:00 a 15:09), la prueba va a ser así:

1. Llega a nuestra estación de trabajo con el software ya montado.
2. Nos pide crear una estrategia delante de él (por ejemplo, un full backup con control file) para que corra **dentro de 10 minutos**.
3. El creador debe producir un **archivo RMAN plano** (por ejemplo `EST001.rma`) que él pueda abrir, y registrarlo en el **catálogo de estrategias**.
4. Se va y regresa a la hora programada. El **ejecutor** tiene que haber corrido solo, sin que nadie lo toque.
5. Revisa que hayan quedado **dos productos**: los archivos del backup y el **log**. *"Si ese log no queda… no hay ninguna evidencia de que esto esté funcionando"* (min. 14:14).

Las pizarras lo resumen en tres piezas:

| Pizarra | Pieza | Qué contiene |
|---|---|---|
| 1 | Creación de la estrategia | **Qué** (tablespace, logs, control file…), **cómo** (full, parcial, incremental), **cuándo** (días L, J, S → horas 13, 15, 17). Produce `Est001.RMA`, que genera un log. |
| 2 y 3 | **Creador** (formulario) | Nombre RMAN, nombre BD, tipo de backup, control file sí/no, días y horas → `[OK]` genera `Est001.RMA` e **inserta en el catálogo**. |
| 2 | **Catálogo de estrategias** | Tabla con columnas **Nombre · Días · Horas** (Est001, Est002, Est003…). |
| 2 y 3 | **Ejecutor** | `abrir catálogo → while true → while no fin catálogo → si día y hora coinciden → system("rman Est001.rma") → al llegar al final: cerrar, abrir y leer otra vez`. |

---

## 2. ¿El proyecto es capaz de hacer un full backup?

**Sí, pero solo si se apaga el modo simulación.** Hoy en `includes/config.php` está `'modo_simulacion' => true`. Con eso, el ejecutor **no llama a RMAN**: inventa una salida de ejemplo y escribe archivos de texto falsos marcados como "PIEZA SIMULADA" (`includes/Ejecutor.php:221-311`). Si el profesor hace la prueba así, no va a haber backup real.

Para la prueba hay que dejar en `includes/config.php`:

```php
'rman_bin'        => 'C:\\app\\<usuario>\\product\\21c\\dbhomeXE\\bin\\rman.exe',
'modo_simulacion' => false,
```

Con el modo real, el ejecutor (`Ejecutor::correrRman`, `includes/Ejecutor.php:182`) hace exactamente lo que dibujó el profesor:

```
rman target "usuario/clave@BD" cmdfile=storage\bg_<fecha>_<id>.rman log=storage\bg_<fecha>_<id>.log
```

Es decir, escribe el script en un archivo, llama a `rman` por sistema operativo (`exec`, el equivalente al `system()` de la pizarra) y lee el log.

Para un full backup de base completa con control file, el script que genera `RmanBuilder` (`includes/RmanBuilder.php`) se ve así:

```
RUN {
  ALLOCATE CHANNEL ch1 DEVICE TYPE DISK;
  BACKUP AS BACKUPSET DATABASE INCLUDE CURRENT CONTROLFILE TAG 'BG_EST001_COMPLE'
         FORMAT 'C:\BackupGuard_RMAN\XUNA\bg_%d_%T_%s_%p.bkp';
  BACKUP SPFILE FORMAT 'C:\BackupGuard_RMAN\XUNA\bg_%d_%T_%s_%p.spfile';
  RELEASE CHANNEL ch1;
}
RESTORE DATABASE VALIDATE;
RESTORE CONTROLFILE VALIDATE;
RESTORE SPFILE VALIDATE;
LIST BACKUP SUMMARY;
REPORT NEED BACKUP;
EXIT;
```

> **Probado contra Oracle real el 2026-10-01 (ejecución #16: exitosa).** Oracle XE 21c en ARCHIVELOG, conectado al CDB `XE` con el usuario `c##bgbackup` (`SYSBACKUP`). Generó 5 archivos (3,09 GB) en `C:\BackupGuard_RMAN\XE` en 18 segundos y los validó con `RESTORE ... VALIDATE`.
>
> Para llegar ahí hubo que corregir cinco errores que el modo simulación escondía:
> 1. `AS SYSDBA` suelto en la conexión de RMAN da `RMAN-01009`. Ahora la cadena va entre comillas simples y usa `AS SYSBACKUP` cuando la casilla SYSDBA está desmarcada (`includes/oracle.php`).
> 2. La salida de RMAN viene en Windows-1252 y MySQL la rechazaba. Ahora se convierte a UTF-8 (`Ejecutor::correrRman`).
> 3. Las rutas de `cmdfile` y `log` tienen espacios (`Bases II`) y daban `RMAN-02001`. Ahora van entre comillas simples.
> 4. `VALIDATE BACKUPSET ALL` no es sintaxis válida de RMAN. Se reemplazó por `RESTORE ... VALIDATE` (`RmanBuilder`).
> 5. RMAN en Windows escribe los nombres en mayúsculas (`BG_XE_...BKP`) y el conteo buscaba `bg_*`. Ahora no distingue mayúsculas (`Ejecutor::contarArchivos`).
>
> Además, Oracle hace un *autobackup* del control file en `C:\app\isaac\product\21c\dbhomeXE\database\C-<DBID>-<fecha>-00`, fuera de la carpeta destino.

---

## 3. ¿Qué archivos quedan cuando se hace un full backup?

Esto es lo que pasa **hoy** y lo que **debería** quedar según el profesor:

| Archivo | ¿Lo pide el profesor? | ¿Qué hace hoy el proyecto? |
|---|---|---|
| Piezas del backup (`bg_<BD>_<fecha>_<set>_<pieza>.bkp`) con los datafiles y el control file | Sí: es "el backup que tuvo" (sus extensiones de ejemplo eran `.BKP` y similares) | ✅ Quedan en la carpeta **destino** de la estrategia. Si el destino está vacío, se van a la Fast Recovery Area de Oracle. |
| Backup del SPFILE (`.spfile`) | Implícito ("¿va a incluir algo más?") | ✅ Queda en el destino si la estrategia marca "incluir SPFILE". |
| Archived redo logs | Solo si la estrategia los incluye | ✅ Con `PLUS ARCHIVELOG` van en piezas `.bkp` en el destino. |
| **Script RMAN** (`EST001.rma`) | **Sí**: *"me tienen que mostrar el RMAN, es un archivito plano"* (min. 02:31) | ⚠️ **No queda como archivo.** Se guarda en MySQL (`estrategias.script_rman` y `ejecuciones.script_ejecutado`) y se ve en pantalla. El archivo `.rman` que se le pasa a RMAN se **borra** apenas termina (`@unlink($cmdfile)`, `Ejecutor.php:215`). |
| **Log de RMAN** (`.log`) | **Sí**: *"me quedan 2 productos: el backup y el log, y ese log tiene que quedar"* | ⚠️ **Queda a medias.** El archivo `.log` sí queda, pero en `storage/` (dentro del proyecto), **no junto al backup**. Su contenido completo también se guarda en MySQL (`ejecuciones.salida_rman`) y se ve en *Historial → Evidencia de ejecución*. |

**Recomendación:** que cada ejecución deje en la **misma carpeta destino** las piezas del backup, una copia del script `.rma` y el `.log`. Así, cuando el profesor abra la carpeta, ve las tres cosas juntas. El script no tiene la contraseña (esa va en la línea de comandos), así que no hay razón de seguridad para borrarlo.

---

## 4. ¿El creador genera el archivo RMAN y lo inserta en el catálogo?

**En parte.**

- ✅ El formulario (`estrategia-form.php`) pide todo lo de la pizarra 3, y más: nombre, base de datos, tipo de backup (completo / incremental 0 / incremental 1 diferencial o acumulativo), control file, SPFILE, archive logs, compresión, paralelismo, retención, días, hora y destino.
- ✅ Al guardar, la estrategia **se inserta en el catálogo** (tabla `estrategias` en MySQL). El profesor dijo explícitamente que el catálogo *"podría ser MySQL o un archivo"* (min. 03:49), así que MySQL es válido.
- ✅ "Generar script" construye el RMAN y lo muestra en pantalla. Además pide **aprobación** del administrador antes de poder ejecutarlo. Esto es un extra que suma: la herramienta propone y el administrador decide.
- ⚠️ **No produce un archivo `EST001.rma` en disco** que se pueda abrir con el Bloc de notas o correr a mano con `rman cmdfile=EST001.rma`. El profesor sí lo espera (min. 07:53: *"me produce un RMAN que se llama EST001.rma que yo lo puedo abrir"*).
- ⚠️ **No hay un código tipo `EST001`.** La estrategia tiene un nombre libre y un `id` numérico. Se puede usar `EST` + id con tres dígitos (EST001, EST002…) como nombre del archivo.

---

## 5. ¿Tenemos un catálogo organizado por id, día y hora?

**Sí hay catálogo, pero no tiene la estructura de días con horas que dibujó el profesor.**

Hoy la tabla `estrategias` (`database/schema.sql`) guarda:

| Campo | Ejemplo | Comentario |
|---|---|---|
| `id` | 1 | Identificador. |
| `nombre` | "Full semanal XUNA" | Nombre libre. |
| `frecuencia` | `semanal` | `unica`, `diaria`, `semanal` o `mensual`. |
| `dias_semana` | `1,3,5` | Lunes, miércoles y viernes. |
| `hora` | `13:00:00` | **Una sola hora para todos los días.** |
| `proxima_ejecucion` | `2026-10-05 13:00:00` | La calcula `Programacion::proxima()`. |

**Diferencia con la pizarra:** el profesor pidió *"un array de días y un array de horas, o una estructura día-hora, día-hora"* (min. 05:49). En la pizarra 1 el ejemplo es **L, J, S a las 13, 15 y 17**, es decir, una hora distinta por día o varias horas. Con el diseño actual eso **no se puede**: una estrategia tiene una sola hora para todos sus días.

**Recomendación:** agregar una tabla de horarios, que es justamente la tabla de la pizarra 2:

```sql
CREATE TABLE estrategia_horarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    estrategia_id INT NOT NULL,
    dia_semana    TINYINT NOT NULL,   -- 1=lunes … 7=domingo
    hora          TIME NOT NULL,
    FOREIGN KEY (estrategia_id) REFERENCES estrategias(id) ON DELETE CASCADE
);
-- EST001: lunes 13:00, jueves 15:00, sábado 17:00
```

Así, "ver el catálogo" es un `SELECT` que muestra **id · nombre · día · hora · archivo .rma**, igual que en la pizarra. Sirve tener una página que lo muestre de esa forma, porque el profesor va a pedir *"muéstreme el catálogo… muéstreme el número 2"* (min. 00:44).

---

## 6. ¿Usamos el Scheduler o tenemos un ejecutor propio?

**Las dos cosas: el ejecutor es nuestro (PHP), pero lo dispara el Programador de tareas de Windows.**

- `scripts/runner.php` es el ejecutor. Lee el catálogo (`pendientesDeEjecutar()`: estrategias activas, aprobadas y cuya `proxima_ejecucion` ya pasó), ejecuta cada una con `Ejecutor`, guarda la evidencia y **termina**. No se queda corriendo.
- `scripts/instalar-tarea-windows.bat` registra ese runner en el **Task Scheduler de Windows** para que se ejecute **cada 5 minutos**. En Linux se usa cron (`scripts/crontab-ejemplo.txt`).

**¿Eso vale para el profesor?** Él aceptó varias formas (min. 01:12): *"el ejecutor puede estar hecho en Python, en C++… o puede ser una tarea programada, o puede ser el Scheduler de Oracle"*. Pero cuando un compañero le propuso usar el scheduler de la computadora (min. 10:19), insistió en la otra idea: *"hay un programa que está dando, dando, dando… yo lo arranco, es infinito"*, y lo dibujó en la pizarra 3 con `WHILE true`.

**Cosas a tener en cuenta:**

1. **La tarea no está registrada en esta computadora.** `schtasks /Query /TN "BackupGuard Runner"` devuelve "no se encuentra". Así como está hoy, **nada se ejecutaría solo**. Hay que correr `scripts\instalar-tarea-windows.bat` como administrador, o usar la opción del punto 3.
2. Con una pasada cada 5 minutos, un backup programado a las 9:40 puede arrancar hasta las 9:45. Para la prueba conviene bajarlo a 1 minuto (`/MO 1`).
3. **Recomendación:** agregar a `runner.php` un modo `--loop` que haga exactamente lo de la pizarra: `while (true) { revisar catálogo; ejecutar lo que toque; sleep(30); }`. Se arranca en una consola y se deja abierta durante la prueba. Así podemos mostrar las dos formas: el ciclo infinito que pidió el profesor y la tarea programada como alternativa.

**Ventaja de nuestro diseño frente al de la pizarra**, por si pregunta: la pizarra compara `día == día del catálogo` y `hora == hora del catálogo`. Si el ejecutor está ocupado justo en ese minuto, ese backup se pierde. Nosotros comparamos `proxima_ejecucion <= ahora`, así que un backup atrasado igual se ejecuta y no se repite, porque después de correr se recalcula la próxima fecha.

---

## 7. ¿Cómo sabe el sistema si el backup funcionó?

Esto ya está bien resuelto y es lo que el profesor llama *"evidencia"*:

- `Ejecutor::clasificar()` **lee el log** y no confía solo en el código de salida. Si aparecen `RMAN-03009`, `ORA-19809`, etc., marca *fallido*; con otros `RMAN-`/`ORA-` marca *advertencia*. Esto cubre el caso del min. 13:12: *"si uno falla, él sigue y pone error en el file 5"*.
- `Ejecutor::contarArchivos()` revisa que **existan archivos nuevos** en el destino. Si RMAN dijo "OK" pero no hay archivos, lo baja a *advertencia*.
- Cada ejecución queda en la tabla `ejecuciones`: inicio, fin, duración, resultado, log completo, script exacto que se usó, cantidad de archivos y tamaño. Se ve en `historial.php` → `ejecucion-detalle.php`.
- Si la estrategia tiene "verificar respaldo", el script agrega `RESTORE DATABASE VALIDATE`, que prueba que el backup sirve para restaurar.

---

## 8. Diferencias con lo pedido, en orden de prioridad

| # | Diferencia | Riesgo en la prueba | Arreglo |
|---|---|---|---|
| 1 | ~~`modo_simulacion = true`~~ | ✅ Resuelto el 2026-10-01: modo real activo y backup real exitoso. | — |
| 2 | ~~No había ejecutor corriendo~~ | ✅ Resuelto: `php scripts/runner.php --loop` (o `iniciar-ejecutor.bat`) es un ciclo infinito que revisa el catálogo cada 30 s. La tarea de Windows sigue disponible como alternativa. | — |
| 3 | ~~No se generaba `EST001.rma`~~ | ✅ Resuelto: al aprobar se escribe `EST###.rma` en `ruta_scripts` (`C:\BackupGuard_RMAN\scripts`). El ejecutor corre ese archivo con `cmdfile=` y ya no lo borra. | — |
| 4 | ~~El log no quedaba junto al backup~~ | ✅ Resuelto: en la carpeta destino quedan `EST###_<fecha>_<hora>.log` y una copia del `.rma` usado. Las piezas ahora se llaman `EST###_<BD>_...BKP`. | — |
| 5 | ~~Una sola hora por estrategia~~ | ✅ Resuelto: tabla `estrategia_horarios`. En frecuencia semanal cada día tiene su hora, o varias ("13:00, 17:00"). Nueva página **Catálogo**. | — |

**Resuelto en la prueba real:** se registró la base apuntando al CDB (`localhost:1521/XE`) con el usuario común `c##bgbackup` (`SYSBACKUP`), y el CDB quedó en ARCHIVELOG con los archivelogs en `C:\BackupGuard_RMAN\arch`. Lo que sigue queda como contexto. El manual (`docs/manual-oracle-xuna-xccss.md`) conecta RMAN directamente a una PDB (XUNA/XCCSS). Oracle restringe algunas operaciones cuando RMAN está conectado a una PDB y no al CDB. Por ejemplo, `CONFIGURE` (que usamos para la retención) no está permitido, y respaldar archivelogs tampoco. Si la estrategia tiene retención o archivelogs, el script podría fallar en la prueba. Opciones: hacer la prueba sin retención ni archivelogs, o registrar la base apuntando al CDB (`XE`), que es lo que normalmente se respalda completo. También confirmar que la base esté en **ARCHIVELOG**: en NOARCHIVELOG un backup con la base abierta falla, y la herramienta solo advierte, no hace el `SHUTDOWN / STARTUP MOUNT`.

---

## 9. Ensayo recomendado antes de la prueba

Hacerlo igual a como lo va a hacer el profesor:

1. `config.php`: `modo_simulacion => false` y la ruta real de `rman.exe`.
2. Desde una consola, confirmar que `rman target /` conecta.
3. Arrancar el ejecutor: registrar la tarea (cada 1 min) o correr `php scripts\runner.php --loop` cuando exista.
4. Crear la estrategia **EST001**: base completa, full, control file sí, destino `C:\BackupGuard_RMAN\XUNA`, hoy dentro de 10 minutos.
5. Generar el script y aprobarlo.
6. No tocar nada y esperar a la hora.
7. Comprobar:
   - en la carpeta destino: piezas `.bkp` reales (de varios MB, no de 64 KB como las simuladas), `.spfile` y, cuando lo arreglemos, `EST001.rma` y el `.log`;
   - en *Historial*: la ejecución con origen **programada**, resultado **exitoso** y `simulado = 0`.
8. Abrir el `.rma` y correrlo a mano (`rman target / cmdfile=EST001.rma log=prueba.log`), porque el profesor también puede pedirlo (min. 14:58).

---

## 10. Cómo hacer la prueba delante del profesor (estado actual)

Requisitos de cada máquina (una sola vez): Oracle en ARCHIVELOG, el usuario `c##bgbackup` con `SYSBACKUP`, y en `config.php` `modo_simulacion => false` con `rman_bin` y `ruta_scripts`. Si su MySQL se creó antes de este cambio, importar `database/migracion-catalogo.sql`.

1. Arrancar la web (`iniciar.bat`) y **el ejecutor** (`iniciar-ejecutor.bat`). Dejar la ventana del ejecutor a la vista: muestra `Catálogo revisado — próxima: ...`.
2. **Creador:** *Estrategias → Nueva estrategia*. Base `XE`, alcance base completa, tipo **Completo**, control file y SPFILE marcados, destino `C:\BackupGuard_RMAN\XE`, estado **Activa**, frecuencia **Semanal**. En la tabla de días, marcar el día de hoy con la hora actual + 10 minutos. Si el profesor lo pide, agregar otros días con otras horas.
3. Guardar → **Generar script** → **Aprobar este script**. En ese momento aparece `C:\BackupGuard_RMAN\scripts\EST###.rma`.
4. Mostrar **Catálogo**: la fila con código, día, hora y archivo `.rma`. Al hacer clic en el archivo se ve su contenido leído desde el disco.
5. Opcional, si pide correrlo a mano: `rman target / cmdfile='C:\BackupGuard_RMAN\scripts\EST###.rma'`.
6. Esperar. A la hora indicada, la ventana del ejecutor muestra `Es el día y la hora de EST### ... → EXITOSO`.
7. Mostrar la evidencia: la carpeta destino con las piezas `EST###_XE_...BKP`, el `EST###_..._.log` y el `.rma`; y en la web, *Historial* con origen **programada**, ejecutado por **ejecutor**, `simulado = 0`.

## Referencias rápidas

| Tema | Archivo |
|---|---|
| Creador (formulario) | `estrategia-form.php` |
| Traducción a RMAN | `includes/RmanBuilder.php` |
| Catálogo | tablas `estrategias` y `estrategia_horarios` en `database/schema.sql`; página `catalogo.php` |
| Cálculo de día y hora | `includes/Programacion.php` |
| Ejecutor | `scripts/runner.php --loop` (`iniciar-ejecutor.bat`) + `includes/Ejecutor.php` |
| Automatización | `scripts/instalar-tarea-windows.bat`, `scripts/crontab-ejemplo.txt` |
| Evidencia | tabla `ejecuciones`; `historial.php`, `ejecucion-detalle.php` |
| Configuración | `includes/config.php` (no se sube a git) |

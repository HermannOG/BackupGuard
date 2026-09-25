# Manual: replicar XUNA y XCCSS en una Oracle real

Curso EIF402 — Administración de Bases de Datos · Universidad Nacional · II ciclo 2026

Este manual explica cómo pasar del escenario **simulado** documentado en
[`simulacion-xuna-xccss.md`](simulacion-xuna-xccss.md) a un escenario **real**,
contra una instancia de Oracle de verdad, por si el grupo necesita reproducirlo
para la demostración en vivo. Complementa a
[`instalacion-local.md`](instalacion-local.md) — ese documento explica cómo dejar
BackupGuard funcionando en general; este se enfoca específicamente en las dos
bases XUNA y XCCSS.

## 0. Punto de partida en esta máquina

Durante la verificación del proyecto se confirmó que este equipo **ya tiene todo lo
necesario instalado**, así que estos pasos no requieren descargar nada:

- Oracle Database XE 21c instalado en `C:\app\isaac\product\21c\dbhomeXE`
  (home `OraDB21Home1`).
- Servicios de Windows activos: `OracleServiceXE` (la instancia) y
  `OracleOraDB21Home1TNSListener` (el listener).
- `sqlplus.exe`, `rman.exe` y `lsnrctl.exe` disponibles en
  `C:\app\isaac\product\21c\dbhomeXE\bin`, y ese `bin` ya está en el `PATH` del
  sistema.
- PHP 8.3.28 con la extensión `oci8` **ya habilitada** (se confirmó con
  `php -m`) — no hace falta instalar Instant Client aparte ni tocar `php.ini`.
- `tnsnames.ora` del home vive en
  `C:\app\isaac\product\21c\homes\OraDB21Home1\network\admin\tnsnames.ora`.

Si se reproduce esto en **otra** máquina que no tenga Oracle instalado, seguir
primero la Parte 2 de [`instalacion-local.md`](docs/instalacion-local.md)
(instalar Oracle XE, habilitar `oci8`) y luego continuar desde el paso 2 de este
documento.

## 1. Nota importante: ARCHIVELOG y arquitectura multitenant

**Léase antes de crear nada.** Desde Oracle 12c (incluyendo XE 21c), el modo de
archivado (`ARCHIVELOG`/`NOARCHIVELOG`) es una propiedad del **CDB completo**
(la instancia raíz), no de cada *pluggable database* (PDB) por separado. Todas las
PDBs de un mismo CDB comparten el mismo `log_mode`.

Eso significa que **no es posible** tener, al mismo tiempo, una PDB llamada XUNA en
ARCHIVELOG y otra llamada XCCSS en NOARCHIVELOG dentro del mismo XE — que es
justamente el contraste que se usó en la simulación para mostrar las dos ramas de
la advertencia. Hay tres formas válidas de resolverlo para la demo real; elegir la
que mejor se acomode al tiempo disponible:

| Opción | Cómo | Cuándo usarla |
|---|---|---|
| **A. Demo secuencial (recomendada)** | Un único CDB. Se deja en `ARCHIVELOG` para XUNA, se registra y se ejecuta esa parte de la demo. Después, **solo si hace falta mostrar la advertencia NOARCHIVELOG en vivo**, se cambia el CDB a `NOARCHIVELOG`, se re-verifica XCCSS desde BackupGuard (el modo cambia para ambas PDBs, pero solo importa lo que se muestra en pantalla en ese momento) y se corre esa parte. Se vuelve a `ARCHIVELOG` al terminar. | Es la opción más simple y no requiere recursos extra. |
| **B. Segunda instancia** | Instalar una segunda instancia Oracle (puede ser un contenedor Docker con `container-registry.oracle.com/database/express`, o una segunda instalación XE con otro `ORACLE_SID`) dedicada exclusivamente a XCCSS en NOARCHIVELOG. | Si se cuenta con tiempo/recursos y se quiere que ambas convivan simultáneamente sin pasos manuales durante la demo. |
| **C. Solo en BackupGuard** | Como se hizo en la simulación: dejar el CDB real en `ARCHIVELOG`, registrar XCCSS igual, y explicar en la presentación que el estado NOARCHIVELOG fue simulado a nivel de aplicación para efectos de la demostración, aclarando la limitación de la opción A/B por tiempo. | Si el objetivo es solo mostrar que la interfaz reacciona a cada modo (ya verificado en `simulacion-xuna-xccss.md`), sin necesidad de una segunda ejecución real contra Oracle. |

Este manual sigue la **Opción A**, por ser la que mejor equilibra fidelidad y
tiempo de preparación.

## 2. Verificar que la instancia está arriba

```
sc query OracleServiceXE
sc query OracleOraDB21Home1TNSListener
lsnrctl status
```

Si el listener no responde, arrancarlo con `lsnrctl start`. Si el servicio
`OracleServiceXE` está detenido, arrancarlo desde "Servicios" de Windows o con
`net start OracleServiceXE`.

Confirmar el PDB base existente (normalmente `XEPDB1`):

```sql
-- sqlplus / as sysdba
SELECT name, open_mode FROM v$pdbs;
```

## 3. Poner el CDB en ARCHIVELOG

Esto se hace **una sola vez**, a nivel de la instancia completa, y es responsabilidad
explícita del DBA — BackupGuard nunca ejecuta este comando (se verificó leyendo
`includes/oracle.php`: solo hace `SELECT`, ninguna sentencia `ALTER`).

```sql
-- sqlplus / as sysdba, conectado al CDB (CDB$ROOT), no a un PDB
SHUTDOWN IMMEDIATE;
STARTUP MOUNT;
ALTER DATABASE ARCHIVELOG;
ALTER DATABASE OPEN;
SELECT log_mode FROM v$database;   -- debe decir ARCHIVELOG
```

## 4. Crear las PDBs XUNA y XCCSS

Oracle XE admite hasta 3 PDBs de usuario además de `PDB$SEED`; confirmar cuántas
hay antes de crear las nuevas con `SELECT COUNT(*) FROM v$pdbs WHERE con_id > 2;`.

```sql
-- Conectado al CDB$ROOT como sysdba
CREATE PLUGGABLE DATABASE xuna
  ADMIN USER pdbadmin_xuna IDENTIFIED BY "clave_temporal_xuna"
  FILE_NAME_CONVERT = ('pdbseed', 'xuna');

CREATE PLUGGABLE DATABASE xccss
  ADMIN USER pdbadmin_xccss IDENTIFIED BY "clave_temporal_xccss"
  FILE_NAME_CONVERT = ('pdbseed', 'xccss');

ALTER PLUGGABLE DATABASE xuna OPEN;
ALTER PLUGGABLE DATABASE xccss OPEN;

-- Que abran solas cuando arranque la instancia:
ALTER PLUGGABLE DATABASE xuna SAVE STATE;
ALTER PLUGGABLE DATABASE xccss SAVE STATE;
```

## 5. Crear el usuario de respaldos en cada PDB

Igual que recomienda [`instalacion-local.md`](instalacion-local.md): nunca usar
`SYS`, y usar `SYSBACKUP` en vez de `SYSDBA` cuando sea suficiente.

```sql
ALTER SESSION SET CONTAINER = xuna;
CREATE USER bgbackup IDENTIFIED BY "clave_segura_xuna";
GRANT SYSBACKUP TO bgbackup;
GRANT CREATE SESSION TO bgbackup;

ALTER SESSION SET CONTAINER = xccss;
CREATE USER bgbackup IDENTIFIED BY "clave_segura_xccss";
GRANT SYSBACKUP TO bgbackup;
GRANT CREATE SESSION TO bgbackup;
```

## 6. Registrar los alias TNS

Editar
`C:\app\isaac\product\21c\homes\OraDB21Home1\network\admin\tnsnames.ora`
y agregar dos entradas (mismo host y puerto que ya usa `XEPDB1`, cambiando el
`SERVICE_NAME` al nombre de cada PDB):

```
XUNA =
  (DESCRIPTION =
    (ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 1521))
    (CONNECT_DATA = (SERVER = DEDICATED)(SERVICE_NAME = xuna))
  )

XCCSS =
  (DESCRIPTION =
    (ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 1521))
    (CONNECT_DATA = (SERVER = DEDICATED)(SERVICE_NAME = xccss))
  )
```

Probar cada alias antes de seguir:

```
tnsping XUNA
tnsping XCCSS
sqlplus bgbackup/clave_segura_xuna@XUNA
```

## 7. Configurar `includes/config.php`

```php
'rman_bin' => 'C:\\app\\isaac\\product\\21c\\dbhomeXE\\bin\\rman.exe',
'oracle_tns_admin' => 'C:\\app\\isaac\\product\\21c\\homes\\OraDB21Home1\\network\\admin',
'modo_simulacion' => false,
```

Confirmar que `rman` responde:

```
rman target /
```

## 8. Registrar las bases en BackupGuard

1. Entrar a `http://localhost:8080/bases-datos.php` como administrador.
2. Registrar **XUNA**: nombre `XUNA`, ambiente `producción`, alias TNS `XUNA`,
   usuario `bgbackup`, contraseña la del paso 5, casilla SYSDBA **sin marcar**
   (se conecta con `SYSBACKUP`, no necesita `AS SYSDBA`).
3. Registrar **XCCSS** igual, con ambiente `pruebas` y alias TNS `XCCSS`.
4. Presionar **Verificar** en cada una. Si todo está bien conectado, la columna
   "Archivado" debe mostrar `ARCHIVELOG` para ambas — recordar la nota de la
   sección 1: en este punto **las dos van a leer el mismo modo**, porque
   comparten CDB. Anotar la lista de tablespaces y datafiles que aparece: se
   necesita para construir la estrategia de datafiles/tablespaces si se quiere
   probar ese alcance.

## 9. Crear, aprobar y ejecutar una estrategia real

Usar los mismos parámetros documentados en `simulacion-xuna-xccss.md` §4.3 como
punto de partida (respaldo completo semanal + incremental diferencial diario para
XUNA), pero:

- Usar un **destino real y pequeño para la prueba** (por ejemplo
  `C:\BackupGuard_RMAN\XUNA`), no la Fast Recovery Area por defecto, para poder
  inspeccionar el resultado fácilmente.
- Empezar con `alcance = base_completa` sin comprimir la primera vez, para que la
  ejecución sea rápida (una PDB recién creada pesa poco).
- Revisar el script generado en pantalla antes de aprobar — es el mismo paso que
  ya se comprobó en la simulación, ahora contra Oracle real.
- Aprobar y ejecutar manualmente desde `estrategia-detalle.php`.
- Confirmar en `ejecucion-detalle.php` que:
  - `simulado = 0` (a diferencia de la simulación, donde siempre era `1`).
  - La salida de RMAN es la real del binario, no el texto generado por
    `Ejecutor::correrSimulado()`.
  - Existen archivos `.bkp` reales en el destino configurado.

## 10. Mostrar la advertencia NOARCHIVELOG en vivo (opcional, Opción A)

Solo si la demostración necesita mostrar la advertencia en tiempo real contra
Oracle de verdad (y no basta con lo ya mostrado en la simulación):

```sql
-- sqlplus / as sysdba, sobre el CDB
SHUTDOWN IMMEDIATE;
STARTUP MOUNT;
ALTER DATABASE NOARCHIVELOG;
ALTER DATABASE OPEN;
```

Volver a `bases-datos.php`, presionar **Verificar** sobre XCCSS: ahora debe
mostrar `NOARCHIVELOG` y disparar la advertencia. Intentar generar un script para
una estrategia de XCCSS con `incluir_archivelogs = 1` marcado: `RmanBuilder`
debe **bloquear** la generación con el error correspondiente (verificado
previamente en modo simulación; el comportamiento es el mismo código, ahora
contra un estado real). Al terminar esta parte de la demo, revertir con
`ALTER DATABASE ARCHIVELOG;` para no dejar la instancia en un estado con menos
garantías de recuperación de las necesarias.

## 11. Automatización real

```
scripts\instalar-tarea-windows.bat
```

ejecutado como administrador, registra la tarea programada "BackupGuard Runner"
cada 5 minutos usando la ruta de `php.exe` indicada dentro del archivo — ajustarla
si no se usa WAMP. Confirmar que corre revisando el Programador de tareas de
Windows y, pasados unos minutos, `historial.php` en BackupGuard: debe aparecer una
ejecución con origen `programada`.

## 12. Problemas comunes específicos de este escenario

| Síntoma | Causa / solución |
|---|---|
| `ORA-65010: maximum number of pluggable databases created` | Se alcanzó el límite de 3 PDBs de usuario de Oracle XE. Borrar una PDB de prueba que no se use (`DROP PLUGGABLE DATABASE x INCLUDING DATAFILES;` con la PDB cerrada) antes de crear XUNA/XCCSS. |
| Las dos bases muestran el mismo modo de archivado después de "Verificar" | Es el comportamiento esperado (sección 1): el modo es del CDB, no del PDB. No es un error de BackupGuard. |
| `ORA-12154: TNS:could not resolve the connect identifier` | El alias no está en el `tnsnames.ora` que `oracle_tns_admin` apunta, o hay un error de sintaxis en el archivo. Revisar con `tnsping XUNA`. |
| `ORA-01031: insufficient privileges` al conectar `bgbackup` | Falta `GRANT SYSBACKUP TO bgbackup;` en esa PDB específica (los privilegios de backup se otorgan por contenedor). |
| El respaldo tarda mucho o llena el disco rápido | La PDB recién creada por defecto trae los tablespaces de ejemplo del `PDB$SEED`; para la demo alcanza con dejarlos, pero si se quiere una prueba más liviana, achicar `retencion_dias` y limpiar el destino entre corridas. |

## 13. Limpieza al terminar

```sql
ALTER PLUGGABLE DATABASE xuna CLOSE IMMEDIATE;
DROP PLUGGABLE DATABASE xuna INCLUDING DATAFILES;

ALTER PLUGGABLE DATABASE xccss CLOSE IMMEDIATE;
DROP PLUGGABLE DATABASE xccss INCLUDING DATAFILES;
```

Y quitar las dos entradas `XUNA`/`XCCSS` del `tnsnames.ora` si no se van a volver a
usar. Si además se quiere volver `modo_simulacion` a `true` para seguir
desarrollando sin depender de Oracle encendido, revertir el paso 7.

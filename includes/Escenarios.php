<?php
/**
 * Escenarios — el CONTROL CORRECTIVO de BackupGuard.
 *
 * Los respaldos (control preventivo) existen para usarse cuando algo falla.
 * Este catálogo describe los fallos de medios posibles en una base Oracle,
 * qué estrategia conviene para cada uno y las instrucciones para recuperar.
 *
 * Además evalúa, para una base registrada, si sus estrategias actuales la
 * dejan preparada para cada escenario (cobertura).
 *
 * BackupGuard NO ejecuta estas instrucciones: restaurar y recuperar son
 * operaciones destructivas que el administrador corre en un ambiente
 * controlado (enunciado, sección 14). La herramienta orienta; la persona decide.
 *
 * Marcadores en las instrucciones:
 *   <n>      número de datafile (sale de REPORT SCHEMA o de V$RECOVER_FILE)
 *   <ts>     nombre del tablespace
 *   <seq>    número de secuencia de log
 *   <DBID>   identificador de la base (lo muestra RMAN al conectarse)
 *   <pieza>  ruta de una pieza de respaldo, p. ej. C:\BackupGuard_RMAN\XE\EST002_....bkp
 */
class Escenarios
{
    /** Tipos de recuperación, para la explicación general de la página. */
    public const TIPOS_RECUPERACION = [
        ['Restauración', 'RESTORE',
         'Copiar de vuelta, desde el respaldo, los archivos dañados o perdidos. Deja los archivos en el estado del momento del respaldo.'],
        ['Recuperación completa (automática)', 'RECOVER DATABASE / RECOVER AUTOMATIC',
         'Aplicar los incrementales, los archived redo logs y los redo logs en línea hasta el último cambio confirmado. RMAN busca y aplica los logs solo. No se pierde nada.'],
        ['Recuperación hasta un momento (UNTIL TIME)', 'SET UNTIL TIME / RECOVER DATABASE UNTIL TIME',
         'Recuperación incompleta: se aplican logs solo hasta una fecha y hora. Sirve para volver a antes de un error. Se pierde lo posterior y la base se abre con RESETLOGS.'],
        ['Recuperación hasta cancelar (UNTIL CANCEL)', 'RECOVER DATABASE UNTIL CANCEL',
         'Recuperación incompleta desde SQL*Plus: Oracle pide los logs uno por uno y el administrador escribe CANCEL cuando falta uno o cuando quiere detenerse. En RMAN el equivalente es SET UNTIL SEQUENCE.'],
    ];

    /** Catálogo completo. */
    public static function todos(): array
    {
        return [

        // ===================================================== DATAFILES
        [
            'id' => 'ESC-01',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de un datafile no crítico',
            'descripcion' => 'Se borra o se daña un datafile de un tablespace de datos (por ejemplo USERS). La base sigue abierta, pero los objetos de ese tablespace dejan de estar disponibles.',
            'causas' => 'Falla del disco, borrado accidental del archivo, antivirus que lo bloquea o lo pone en cuarentena.',
            'sintomas' => ['ORA-01157', 'ORA-01110', 'ORA-01116'],
            'detectar' => "SELECT file#, error FROM v\$recover_file;",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Completa, con la base abierta',
            'estrategia' => 'Base en ARCHIVELOG con un respaldo de la base completa o del tablespace, más los archived redo logs. Se restaura solo ese datafile mientras el resto de la base sigue funcionando.',
            'instrucciones' => <<<'TXT'
# 1. Identificar el archivo
REPORT SCHEMA;

# 2. Dejarlo fuera de línea, restaurar y recuperar (base abierta)
ALTER DATABASE DATAFILE <n> OFFLINE;
RESTORE DATAFILE <n>;
RECOVER DATAFILE <n>;
ALTER DATABASE DATAFILE <n> ONLINE;
TXT,
            'nota' => 'Si el datafile pertenece a una PDB (por ejemplo XEPDB1), el OFFLINE y el ONLINE se hacen dentro de ella: en SQL*Plus, ALTER SESSION SET CONTAINER = XEPDB1;',
        ],
        [
            'id' => 'ESC-02',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de un datafile crítico (SYSTEM o UNDO)',
            'descripcion' => 'Se pierde un datafile del tablespace SYSTEM o del tablespace de UNDO en uso. Oracle no puede seguir sin él: la instancia se cae o la base no abre.',
            'causas' => 'Falla del disco donde está SYSTEM o UNDO, borrado del archivo.',
            'sintomas' => ['ORA-01157', 'ORA-01110', 'ORA-00376'],
            'detectar' => "SELECT file#, error FROM v\$recover_file;",
            'exposicion' => 'Alto',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Completa, con la base montada',
            'estrategia' => 'Base en ARCHIVELOG con respaldo de la base completa y archived redo logs. Como SYSTEM no puede quedar fuera de línea, la base se detiene y se recupera montada.',
            'instrucciones' => <<<'TXT'
SHUTDOWN ABORT;
STARTUP MOUNT;
RESTORE DATAFILE <n>;
RECOVER DATAFILE <n>;
ALTER DATABASE OPEN;
TXT,
            'nota' => 'En Oracle XE el datafile 1 es SYSTEM y el de UNDOTBS1 suele ser el 4. Confirmalo con REPORT SCHEMA.',
        ],
        [
            'id' => 'ESC-03',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de un tablespace completo',
            'descripcion' => 'Se pierden todos los datafiles de un tablespace de datos, por ejemplo porque estaban en el mismo disco.',
            'causas' => 'Falla del disco que contenía el tablespace, borrado de la carpeta.',
            'sintomas' => ['ORA-01157', 'ORA-01110', 'ORA-00376'],
            'detectar' => "SELECT d.file#, d.name, r.error\n  FROM v\$recover_file r JOIN v\$datafile d ON d.file# = r.file#;",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Completa, con la base abierta',
            'estrategia' => 'Respaldo de la base completa o una estrategia parcial por tablespaces para los de mayor prioridad, en ARCHIVELOG. Se restaura el tablespace entero sin detener la base.',
            'instrucciones' => <<<'TXT'
ALTER TABLESPACE <ts> OFFLINE IMMEDIATE;
RESTORE TABLESPACE <ts>;
RECOVER TABLESPACE <ts>;
ALTER TABLESPACE <ts> ONLINE;
TXT,
            'nota' => 'No aplica a SYSTEM ni al UNDO en uso: para esos, ver ESC-02.',
        ],
        [
            'id' => 'ESC-04',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de una base enchufable (PDB) completa',
            'descripcion' => 'Se pierden los datafiles de una PDB, como XEPDB1 en Oracle XE. El contenedor (CDB) y las demás PDBs siguen funcionando.',
            'causas' => 'Falla del disco o borrado de la carpeta de la PDB.',
            'sintomas' => ['ORA-01157', 'ORA-01110', 'ORA-65011'],
            'detectar' => "SELECT con_id, file#, error FROM v\$recover_file;",
            'exposicion' => 'Alto',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Completa, con el CDB abierto',
            'estrategia' => 'Respaldo del CDB completo (que incluye todas las PDBs) en ARCHIVELOG. Se restaura solo la PDB afectada.',
            'instrucciones' => <<<'TXT'
# RMAN conectado al CDB (XE)
ALTER PLUGGABLE DATABASE XEPDB1 CLOSE ABORT;
RESTORE PLUGGABLE DATABASE XEPDB1;
RECOVER PLUGGABLE DATABASE XEPDB1;
ALTER PLUGGABLE DATABASE XEPDB1 OPEN;
TXT,
            'nota' => 'Por eso BackupGuard respalda el CDB XE y no solo XEPDB1: un respaldo del CDB permite recuperar cualquiera de sus PDBs.',
        ],
        [
            'id' => 'ESC-05',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de un datafile creado después del último respaldo',
            'descripcion' => 'Se agregó un datafile nuevo y se perdió antes de que existiera un respaldo que lo incluyera.',
            'causas' => 'Falla del disco o borrado poco después de crear el datafile.',
            'sintomas' => ['ORA-01157', 'ORA-01110'],
            'detectar' => "SELECT file#, error FROM v\$recover_file;",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad', 'Integridad'],
            'perdida' => 'Sin pérdida, si existen todos los archived logs desde su creación',
            'recuperacion' => 'Completa, recreando el archivo',
            'estrategia' => 'ARCHIVELOG y respaldo frecuente de los archived redo logs. RMAN recrea el datafile vacío y le aplica todos los cambios desde que se creó.',
            'instrucciones' => <<<'TXT'
ALTER DATABASE DATAFILE <n> OFFLINE;
RESTORE DATAFILE <n>;      # sin respaldo, RMAN crea el archivo vacío
RECOVER DATAFILE <n>;      # aplica los archived logs desde su creación
ALTER DATABASE DATAFILE <n> ONLINE;
TXT,
            'nota' => 'Después de agregar un datafile conviene correr un respaldo nuevo cuanto antes.',
        ],
        [
            'id' => 'ESC-06',
            'categoria' => 'Datafiles',
            'nombre' => 'Pérdida de un tempfile (tablespace TEMP)',
            'descripcion' => 'Se pierde el archivo del tablespace temporal. No guarda datos permanentes, así que no se restaura: se vuelve a crear.',
            'causas' => 'Borrado del archivo o falla del disco.',
            'sintomas' => ['ORA-01157', 'ORA-01187', 'ORA-01110'],
            'detectar' => "SELECT file#, name, status FROM v\$tempfile;",
            'exposicion' => 'Bajo',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'No requiere respaldo',
            'estrategia' => 'Ninguna estrategia de respaldo lo cubre ni hace falta: RMAN no respalda tempfiles. Basta con crear uno nuevo.',
            'instrucciones' => <<<'TXT'
-- SQL*Plus como administrador
ALTER TABLESPACE TEMP ADD TEMPFILE 'C:\app\<usuario>\product\21c\oradata\XE\TEMP02.DBF' SIZE 100M;
ALTER DATABASE TEMPFILE '<ruta del tempfile perdido>' DROP;
TXT,
            'nota' => 'Oracle también recrea los tempfiles faltantes al reiniciar la instancia.',
        ],

        // ===================================================== REDO LOGS
        [
            'id' => 'ESC-07',
            'categoria' => 'Redo logs',
            'nombre' => 'Pérdida de un miembro de un grupo de redo log multiplexado',
            'descripcion' => 'Un grupo de redo log tiene dos copias (miembros) y se pierde una. Oracle sigue escribiendo en la otra, así que la base continúa funcionando.',
            'causas' => 'Falla del disco donde estaba esa copia, borrado del archivo.',
            'sintomas' => ['ORA-00313', 'ORA-00312', 'ORA-00321'],
            'detectar' => "SELECT group#, member, status FROM v\$logfile;",
            'exposicion' => 'Bajo',
            'riesgos' => ['Integridad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'No requiere respaldo',
            'estrategia' => 'Multiplexar los redo logs: cada grupo con al menos dos miembros en discos distintos. Es una configuración de Oracle, no una estrategia de respaldo.',
            'instrucciones' => <<<'TXT'
-- SQL*Plus. Si el grupo es el CURRENT, cambiar de log primero:
ALTER SYSTEM SWITCH LOGFILE;
ALTER DATABASE DROP LOGFILE MEMBER '<ruta del miembro perdido>';
ALTER DATABASE ADD LOGFILE MEMBER '<ruta del miembro nuevo>' TO GROUP <grupo>;
TXT,
            'nota' => 'Oracle XE crea cada grupo con un solo miembro. Sin multiplexar, este escenario se convierte en ESC-08 o ESC-09.',
        ],
        [
            'id' => 'ESC-08',
            'categoria' => 'Redo logs',
            'nombre' => 'Pérdida de un grupo de redo log inactivo',
            'descripcion' => 'Se pierde un grupo de redo log completo que no estaba en uso (estado INACTIVE). Sus cambios ya están en los datafiles.',
            'causas' => 'Falla del disco, borrado del archivo.',
            'sintomas' => ['ORA-00313', 'ORA-00312'],
            'detectar' => "SELECT group#, sequence#, status, archived FROM v\$log;",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'No requiere respaldo',
            'estrategia' => 'Multiplexar los redo logs. Si el grupo no se había archivado, hay que hacer un respaldo completo enseguida, porque queda un hueco en la cadena de archived logs.',
            'instrucciones' => <<<'TXT'
-- SQL*Plus. Si la base no abre: STARTUP MOUNT; primero
ALTER DATABASE CLEAR LOGFILE GROUP <grupo>;
-- Si el grupo no estaba archivado (columna ARCHIVED = NO):
ALTER DATABASE CLEAR UNARCHIVED LOGFILE GROUP <grupo>;
-- y luego, de inmediato, un respaldo nivel 0 de toda la base.
ALTER DATABASE OPEN;    -- solo si se había montado
TXT,
            'nota' => '',
        ],
        [
            'id' => 'ESC-09',
            'categoria' => 'Redo logs',
            'nombre' => 'Pérdida del grupo de redo log actual (CURRENT)',
            'descripcion' => 'Se pierde el grupo en el que Oracle estaba escribiendo. Contenía cambios confirmados que todavía no se habían archivado, así que no hay forma de recuperarlos.',
            'causas' => 'Falla del disco de los redo logs mientras la base trabajaba.',
            'sintomas' => ['ORA-00313', 'ORA-00312', 'ORA-00327', 'caída de la instancia'],
            'detectar' => "SELECT group#, sequence#, status FROM v\$log;",
            'exposicion' => 'Crítico',
            'riesgos' => ['Disponibilidad', 'Integridad'],
            'perdida' => 'Con pérdida: se pierden los cambios del log actual',
            'recuperacion' => 'Incompleta (UNTIL CANCEL o UNTIL SEQUENCE)',
            'estrategia' => 'Respaldo completo, archived redo logs respaldados con frecuencia y redo logs multiplexados (que es lo único que evita este escenario). Se restaura la base y se recupera hasta el último log disponible.',
            'instrucciones' => <<<'TXT'
# RMAN. <seq> = SEQUENCE# del grupo perdido, según V$LOG
SHUTDOWN ABORT;
STARTUP MOUNT;
RUN {
  SET UNTIL SEQUENCE <seq> THREAD 1;   # recupera hasta antes del log perdido
  RESTORE DATABASE;
  RECOVER DATABASE;
}
ALTER DATABASE OPEN RESETLOGS;

# Equivalente en SQL*Plus, después de RESTORE DATABASE:
#   RECOVER DATABASE UNTIL CANCEL;
#   (aplicar los logs que pide y escribir CANCEL al llegar al perdido)
#   ALTER DATABASE OPEN RESETLOGS;
TXT,
            'nota' => 'Después de OPEN RESETLOGS empieza una nueva encarnación de la base: hay que hacer un respaldo nivel 0 de inmediato.',
        ],

        // ===================================================== CONTROL FILE Y PARÁMETROS
        [
            'id' => 'ESC-10',
            'categoria' => 'Control file y parámetros',
            'nombre' => 'Pérdida de una copia del control file',
            'descripcion' => 'El control file está multiplexado (Oracle XE crea dos copias) y se pierde una. La instancia se detiene, pero la otra copia está sana.',
            'causas' => 'Falla del disco o borrado de una de las copias.',
            'sintomas' => ['ORA-00205', 'ORA-00210', 'ORA-00202'],
            'detectar' => "SHOW PARAMETER control_files\n-- y revisar el alert log para ver cuál falta",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'No requiere respaldo',
            'estrategia' => 'Multiplexar el control file en discos distintos. Se copia la versión sana sobre la perdida, a nivel de sistema operativo.',
            'instrucciones' => <<<'TXT'
-- 1. SQL*Plus
SHUTDOWN ABORT;

REM 2. Consola de Windows: copiar la copia sana sobre la perdida
copy C:\app\<usuario>\product\21c\oradata\XE\CONTROL01.CTL C:\app\<usuario>\product\21c\oradata\XE\CONTROL02.CTL

-- 3. SQL*Plus
STARTUP;
TXT,
            'nota' => '',
        ],
        [
            'id' => 'ESC-11',
            'categoria' => 'Control file y parámetros',
            'nombre' => 'Pérdida de todos los control files',
            'descripcion' => 'Se pierden todas las copias del control file. Sin él, Oracle no sabe qué archivos forman la base ni en qué estado están: no puede montarla.',
            'causas' => 'Todas las copias estaban en el mismo disco y ese disco falló.',
            'sintomas' => ['ORA-00205', 'ORA-00202', 'ORA-27041'],
            'detectar' => "-- La base no pasa de NOMOUNT; el alert log indica los control files faltantes",
            'exposicion' => 'Alto',
            'riesgos' => ['Disponibilidad', 'Integridad'],
            'perdida' => 'Sin pérdida, si los redo logs en línea están intactos',
            'recuperacion' => 'Completa, abriendo con RESETLOGS',
            'estrategia' => 'Incluir el control file en cada respaldo (BackupGuard lo hace con INCLUDE CURRENT CONTROLFILE) y mantener activo el autobackup. Alternativa: tener una copia en texto (BACKUP CONTROLFILE TO TRACE) para recrearlo.',
            'instrucciones' => <<<'TXT'
# RMAN
STARTUP NOMOUNT;
SET DBID <DBID>;
RESTORE CONTROLFILE FROM AUTOBACKUP;
# o desde una pieza de BackupGuard:
#   RESTORE CONTROLFILE FROM '<pieza>';
ALTER DATABASE MOUNT;
RECOVER DATABASE;
ALTER DATABASE OPEN RESETLOGS;

# Prevención: guardar el control file como script de texto
#   ALTER DATABASE BACKUP CONTROLFILE TO TRACE AS 'C:\BackupGuard_RMAN\control_trace.sql';
TXT,
            'nota' => 'El DBID aparece al conectarse con RMAN, por ejemplo "connected to target database: XE (DBID=3106665054)": conviene anotarlo junto a los respaldos. Desde Oracle 12.2 el autobackup del control file viene activado; se confirma con SHOW CONTROLFILE AUTOBACKUP.',
        ],
        [
            'id' => 'ESC-12',
            'categoria' => 'Control file y parámetros',
            'nombre' => 'Pérdida del SPFILE',
            'descripcion' => 'Se pierde el archivo de parámetros del servidor. Si la instancia está levantada no pasa nada inmediato, pero ya no podrá volver a arrancar.',
            'causas' => 'Borrado del archivo, falla del disco de Oracle Home.',
            'sintomas' => ['ORA-01078', 'LRM-00109'],
            'detectar' => "SHOW PARAMETER spfile",
            'exposicion' => 'Medio',
            'riesgos' => ['Disponibilidad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Restauración del archivo de parámetros',
            'estrategia' => 'Incluir el SPFILE en la estrategia (BACKUP SPFILE) y mantener el autobackup. Si la instancia sigue arriba, se puede regenerar desde la memoria.',
            'instrucciones' => <<<'TXT'
-- Si la instancia sigue levantada (SQL*Plus):
CREATE PFILE FROM MEMORY;
CREATE SPFILE FROM PFILE;

# Si la instancia está caída (RMAN):
STARTUP FORCE NOMOUNT;      # RMAN arranca con parámetros mínimos
SET DBID <DBID>;
RESTORE SPFILE FROM AUTOBACKUP;
# o: RESTORE SPFILE FROM '<pieza .spfile de BackupGuard>';
STARTUP FORCE;
TXT,
            'nota' => '',
        ],

        // ===================================================== ARCHIVED LOGS Y BLOQUES
        [
            'id' => 'ESC-13',
            'categoria' => 'Archived logs y bloques',
            'nombre' => 'Pérdida de archived redo logs (brecha en la cadena)',
            'descripcion' => 'Faltan archived redo logs que no estaban respaldados. No hay daño visible hoy, pero cualquier recuperación futura solo podrá llegar hasta el log que falta.',
            'causas' => 'Borrado manual de la carpeta de archivados para liberar espacio, falla del disco de archivados.',
            'sintomas' => ['RMAN-06025', 'RMAN-06054', 'RMAN-06059'],
            'detectar' => "CROSSCHECK ARCHIVELOG ALL;\nLIST EXPIRED ARCHIVELOG ALL;",
            'exposicion' => 'Alto',
            'riesgos' => ['Integridad'],
            'perdida' => 'Riesgo latente de pérdida',
            'recuperacion' => 'Preventiva: nuevo respaldo completo',
            'estrategia' => 'Respaldar los archived redo logs con frecuencia (PLUS ARCHIVELOG o una estrategia solo de archivados) y nunca borrarlos a mano: solo con RMAN, después de respaldarlos.',
            'instrucciones' => <<<'TXT'
CROSSCHECK ARCHIVELOG ALL;
DELETE NOPROMPT EXPIRED ARCHIVELOG ALL;
# Cerrar la brecha: respaldo nuevo de inmediato
BACKUP INCREMENTAL LEVEL 0 DATABASE PLUS ARCHIVELOG;
TXT,
            'nota' => 'Si además hay que recuperar la base, solo se puede llegar hasta la secuencia anterior a la que falta (ver ESC-09).',
        ],
        [
            'id' => 'ESC-14',
            'categoria' => 'Archived logs y bloques',
            'nombre' => 'Corrupción de bloques en un datafile',
            'descripcion' => 'Uno o varios bloques de un datafile quedan dañados. El resto del archivo funciona, pero las consultas que leen esos bloques fallan.',
            'causas' => 'Falla de hardware o de controladora de disco, escritura interrumpida.',
            'sintomas' => ['ORA-01578', 'ORA-01110', 'ORA-26040'],
            'detectar' => "VALIDATE DATABASE;   # en RMAN\nSELECT * FROM v\$database_block_corruption;   -- en SQL*Plus",
            'exposicion' => 'Medio',
            'riesgos' => ['Integridad'],
            'perdida' => 'Sin pérdida de datos',
            'recuperacion' => 'Completa, por bloque o por datafile',
            'estrategia' => 'Respaldos verificados (BackupGuard usa RESTORE ... VALIDATE) y ARCHIVELOG. Se recuperan solo los bloques dañados.',
            'instrucciones' => <<<'TXT'
# RMAN
VALIDATE DATABASE;
RECOVER CORRUPTION LIST;
# o un bloque concreto:
#   RECOVER DATAFILE <n> BLOCK <bloque>;
TXT,
            'nota' => 'La recuperación por bloques es una función de Enterprise Edition. En otras ediciones se restaura y recupera el datafile completo (ESC-01).',
        ],

        // ===================================================== DESASTRE
        [
            'id' => 'ESC-15',
            'categoria' => 'Desastre y errores lógicos',
            'nombre' => 'Pérdida total del servidor o del disco',
            'descripcion' => 'Se pierden todos los archivos de la base: datafiles, control files, redo logs y SPFILE. Solo quedan los respaldos, si estaban en otro lugar.',
            'causas' => 'Falla total del disco o del servidor, ransomware, robo o desastre físico.',
            'sintomas' => ['ORA-01078', 'ORA-00205', 'la instancia no arranca'],
            'detectar' => '-- No hay base que consultar: se parte de los respaldos',
            'exposicion' => 'Crítico',
            'riesgos' => ['Disponibilidad', 'Integridad'],
            'perdida' => 'Con pérdida: se pierden los cambios del último log no archivado',
            'recuperacion' => 'Incompleta, hasta el último archived log',
            'estrategia' => 'Respaldo completo con control file, SPFILE y archived redo logs, guardado FUERA del servidor (disco externo, NAS u otra sede). Sin una copia externa, este escenario no tiene solución.',
            'instrucciones' => <<<'TXT'
# 1. Instalar Oracle con la misma versión y crear las mismas carpetas
# 2. En RMAN:
STARTUP FORCE NOMOUNT;
SET DBID <DBID>;
RESTORE SPFILE FROM '<pieza .spfile>';
STARTUP FORCE NOMOUNT;
RESTORE CONTROLFILE FROM '<pieza con el control file>';
ALTER DATABASE MOUNT;
CATALOG START WITH 'C:\BackupGuard_RMAN\XE\' NOPROMPT;
RESTORE DATABASE;
RECOVER DATABASE;          # termina con RMAN-06054 al agotar los logs: es lo esperado
ALTER DATABASE OPEN RESETLOGS;
TXT,
            'nota' => 'Es el escenario que justifica identificar el dispositivo de destino y guardar los respaldos fuera del equipo donde está la base.',
        ],
        [
            'id' => 'ESC-16',
            'categoria' => 'Desastre y errores lógicos',
            'nombre' => 'Pérdida de un datafile con la base en NOARCHIVELOG',
            'descripcion' => 'Se pierde cualquier datafile y la base no guarda archived redo logs. No se puede recuperar hasta el último cambio: solo volver al estado del último respaldo.',
            'causas' => 'Las mismas que ESC-01, en una base sin ARCHIVELOG.',
            'sintomas' => ['ORA-01157', 'ORA-01110'],
            'detectar' => "SELECT log_mode FROM v\$database;",
            'exposicion' => 'Crítico',
            'riesgos' => ['Disponibilidad', 'Integridad'],
            'perdida' => 'Con pérdida: todo lo posterior al último respaldo',
            'recuperacion' => 'Restauración completa sin recuperación',
            'estrategia' => 'Cambiar la base a ARCHIVELOG. Mientras siga en NOARCHIVELOG, respaldos completos frecuentes con la base montada (en frío), aceptando perder lo hecho entre respaldos.',
            'instrucciones' => <<<'TXT'
SHUTDOWN ABORT;
STARTUP NOMOUNT;
RESTORE CONTROLFILE FROM '<pieza del último respaldo>';
ALTER DATABASE MOUNT;
RESTORE DATABASE;
RECOVER DATABASE NOREDO;
ALTER DATABASE OPEN RESETLOGS;
TXT,
            'nota' => 'Por eso BackupGuard advierte cuando una base está en NOARCHIVELOG (sección 6 del enunciado).',
        ],
        [
            'id' => 'ESC-17',
            'categoria' => 'Desastre y errores lógicos',
            'nombre' => 'Error de usuario: datos borrados o tabla eliminada',
            'descripcion' => 'No es un fallo de medios: los archivos están sanos, pero alguien borró datos o una tabla por error. Se resuelve volviendo la base a un momento anterior.',
            'causas' => 'DELETE o DROP TABLE equivocado, script de mantenimiento mal escrito.',
            'sintomas' => ['ORA-00942', 'datos faltantes reportados por los usuarios'],
            'detectar' => "SELECT object_name, droptime FROM dba_recyclebin;",
            'exposicion' => 'Alto',
            'riesgos' => ['Integridad'],
            'perdida' => 'Con pérdida: todo lo posterior al momento elegido',
            'recuperacion' => 'Incompleta (UNTIL TIME)',
            'estrategia' => 'ARCHIVELOG con respaldo completo y archived logs: permiten volver a cualquier momento dentro de la ventana de retención. Primero conviene intentar las opciones sin restauración (papelera de reciclaje, Flashback).',
            'instrucciones' => <<<'TXT'
-- Primero, sin restaurar (SQL*Plus):
FLASHBACK TABLE <tabla> TO BEFORE DROP;

# Si no alcanza, volver la base a antes del error (RMAN):
SHUTDOWN IMMEDIATE;
STARTUP MOUNT;
RUN {
  SET UNTIL TIME "TO_DATE('<AAAA-MM-DD HH24:MI>', 'YYYY-MM-DD HH24:MI')";
  RESTORE DATABASE;
  RECOVER DATABASE;
}
ALTER DATABASE OPEN RESETLOGS;
TXT,
            'nota' => 'En SQL*Plus el equivalente es RECOVER DATABASE UNTIL TIME \'AAAA-MM-DD:HH24:MI:SS\'; después de restaurar los datafiles. En un CDB, esto devuelve todas las PDBs al mismo momento.',
        ],
        ];
    }

    // =================================================================
    // COBERTURA — ¿las estrategias de esta base la preparan para cada fallo?
    // =================================================================

    /**
     * Resume qué protegen las estrategias aprobadas de una base.
     */
    public static function contexto(PDO $pdo, array $base): array
    {
        $stmt = $pdo->prepare("
            SELECT * FROM estrategias
             WHERE base_datos_id = :b AND aprobado = 1
        ");
        $stmt->execute(['b' => $base['id']]);
        $est = $stmt->fetchAll();

        $hay = fn(callable $f) => count(array_filter($est, $f)) > 0;

        $ok = $pdo->prepare("
            SELECT MAX(inicio) FROM ejecuciones
             WHERE base_datos_id = :b AND resultado = 'exitoso' AND simulado = 0
        ");
        $ok->execute(['b' => $base['id']]);
        $ultimoExito = $ok->fetchColumn() ?: null;

        return [
            'archivelog'   => ($base['modo_archivado'] ?? '') === 'ARCHIVELOG',
            'noarchivelog' => ($base['modo_archivado'] ?? '') === 'NOARCHIVELOG',
            'desconocido'  => ($base['modo_archivado'] ?? 'DESCONOCIDO') === 'DESCONOCIDO',
            'estrategias'  => count($est),
            'completa'     => $hay(fn($e) => $e['alcance'] === 'base_completa'),
            'parcial'      => $hay(fn($e) => $e['alcance'] !== 'base_completa'),
            'controlfile'  => $hay(fn($e) => (int) $e['incluir_controlfile'] === 1),
            'spfile'       => $hay(fn($e) => (int) $e['incluir_spfile'] === 1),
            'archivelogs'  => $hay(fn($e) => (int) $e['incluir_archivelogs'] === 1),
            'verifica'     => $hay(fn($e) => (int) $e['verificar_respaldo'] === 1),
            'destino'      => $hay(fn($e) => trim((string) $e['destino']) !== ''),
            'ultimo_exito' => $ultimoExito,
        ];
    }

    /**
     * Estado de cobertura de un escenario.
     * @return array{0:string, 1:string} [cubierto|parcial|sin|noaplica|config, explicación]
     */
    public static function cobertura(string $id, array $c): array
    {
        $sinRespaldo = !$c['ultimo_exito']
            ? ' Todavía no hay una ejecución real exitosa: hasta que exista, el respaldo no se puede usar.'
            : '';
        $arch = $c['archivelog'];
        $falta = function (array $cond) {
            $f = [];
            foreach ($cond as $txt => $ok) { if (!$ok) { $f[] = $txt; } }
            return $f;
        };

        switch ($id) {
            case 'ESC-01':
            case 'ESC-03':
                if (!$arch) {
                    return ['sin', 'Requiere ARCHIVELOG: con la base en ' . self::modo($c) . ' solo se puede volver al último respaldo (ESC-16).'];
                }
                if (!$c['completa'] && !$c['parcial']) {
                    return ['sin', 'No hay ninguna estrategia aprobada que respalde datafiles.'];
                }
                return self::conRespaldo($c, 'Hay respaldo de datafiles y la base está en ARCHIVELOG.', $sinRespaldo, $c['archivelogs']);

            case 'ESC-02':
            case 'ESC-04':
            case 'ESC-14':
                if (!$arch) {
                    return ['sin', 'Requiere ARCHIVELOG.'];
                }
                if (!$c['completa']) {
                    return ['sin', 'Hace falta una estrategia aprobada de la base completa.'];
                }
                if ($id === 'ESC-14') {
                    return ['parcial', 'Hay respaldo completo, pero la recuperación por bloques requiere Enterprise Edition; en XE se recupera el datafile entero.' . $sinRespaldo];
                }
                return self::conRespaldo($c, 'Hay respaldo de la base completa en ARCHIVELOG.', $sinRespaldo, $c['archivelogs']);

            case 'ESC-05':
                if (!$arch || !$c['archivelogs']) {
                    return ['sin', 'Requiere ARCHIVELOG y que alguna estrategia respalde los archived redo logs.'];
                }
                return ['cubierto', 'Los archived redo logs se respaldan: RMAN puede recrear el datafile y aplicarle los cambios.'];

            case 'ESC-06':
                return ['config', 'No depende de los respaldos: el tempfile se vuelve a crear.'];

            case 'ESC-07':
            case 'ESC-08':
            case 'ESC-10':
                return ['config', 'No depende de los respaldos: lo evita multiplexar los ' . ($id === 'ESC-10' ? 'control files' : 'redo logs') . ' en discos distintos.'];

            case 'ESC-09':
                $f = $falta(['ARCHIVELOG' => $arch, 'respaldo completo' => $c['completa'], 'archived logs' => $c['archivelogs']]);
                return $f
                    ? ['sin', 'Falta: ' . implode(', ', $f) . '.']
                    : ['parcial', 'Se puede restaurar y recuperar hasta el log perdido, pero sus cambios se pierden. Solo multiplexar los redo logs evita la pérdida.' . $sinRespaldo];

            case 'ESC-11':
                $f = $falta(['control file en el respaldo' => $c['controlfile'], 'respaldo completo' => $c['completa']]);
                return $f ? ['sin', 'Falta: ' . implode(', ', $f) . '.'] : self::conRespaldo($c, 'El control file va incluido en el respaldo.', $sinRespaldo, true);

            case 'ESC-12':
                return $c['spfile']
                    ? self::conRespaldo($c, 'El SPFILE se respalda.', $sinRespaldo, true)
                    : ['sin', 'Ninguna estrategia aprobada respalda el SPFILE.'];

            case 'ESC-13':
                return ($arch && $c['archivelogs'])
                    ? ['cubierto', 'Los archived redo logs se respaldan y se borran solo con RMAN, lo que reduce el riesgo de una brecha.']
                    : ['sin', $arch ? 'La base genera archived logs, pero ninguna estrategia los respalda.' : 'No aplica mientras la base esté en NOARCHIVELOG, pero esa condición es peor (ESC-16).'];

            case 'ESC-15':
                $f = $falta(['respaldo completo' => $c['completa'], 'control file' => $c['controlfile'],
                             'SPFILE' => $c['spfile'], 'archived logs' => $c['archivelogs']]);
                if ($f) {
                    return ['sin', 'Falta: ' . implode(', ', $f) . '.'];
                }
                return ['parcial', 'Los respaldos tienen todo lo necesario. Confirmá que la carpeta de destino esté en otro disco o equipo: si está en el mismo servidor, se pierde junto con la base.' . $sinRespaldo];

            case 'ESC-16':
                if ($arch) {
                    return ['noaplica', 'No aplica: la base está en ARCHIVELOG.'];
                }
                return ($c['completa'] && $c['controlfile'])
                    ? ['parcial', 'Se puede volver al último respaldo, perdiendo todo lo posterior. Cambiar a ARCHIVELOG elimina este escenario.' . $sinRespaldo]
                    : ['sin', 'Hace falta un respaldo completo con control file.'];

            case 'ESC-17':
                $f = $falta(['ARCHIVELOG' => $arch, 'respaldo completo' => $c['completa'], 'archived logs' => $c['archivelogs']]);
                return $f
                    ? ['sin', 'Falta: ' . implode(', ', $f) . '.']
                    : self::conRespaldo($c, 'Se puede volver a cualquier momento dentro de la ventana de retención.', $sinRespaldo, true);
        }
        return ['sin', ''];
    }

    private static function conRespaldo(array $c, string $ok, string $sinRespaldo, bool $conArchivados): array
    {
        if (!$conArchivados) {
            return ['parcial', $ok . ' Sin respaldar los archived logs, la recuperación depende de que sigan en disco.' . $sinRespaldo];
        }
        return $sinRespaldo === '' ? ['cubierto', $ok] : ['parcial', $ok . $sinRespaldo];
    }

    private static function modo(array $c): string
    {
        return $c['noarchivelog'] ? 'NOARCHIVELOG' : 'modo desconocido';
    }
}

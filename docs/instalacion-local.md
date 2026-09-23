# Instalación local

BackupGuard corre en la misma computadora donde está Oracle (o donde está
instalado el cliente de Oracle con acceso a la base). No se despliega en un
hosting: no existe hosting gratuito con Oracle, y una herramienta que guarda
credenciales SYSDBA y ejecuta RMAN no debería estar expuesta en internet.

Por eso el servidor solo escucha en `127.0.0.1` y `includes/db.php` rechaza
cualquier petición que no venga de la propia máquina.

---

## Parte 1 — Lo mínimo para que arranque (sin Oracle todavía)

Con esto la herramienta funciona completa en **modo simulación**: podés crear
estrategias, generar scripts RMAN, aprobarlos, "ejecutarlos" y ver la
evidencia. Sirve para desarrollar y para la mayor parte de la demostración.

### 1. PHP y MySQL

La vía más corta es **XAMPP** (incluye PHP 8 y MariaDB):
descargalo, instalalo y desde el panel de control arrancá **MySQL**.
No hace falta arrancar Apache: BackupGuard usa el servidor propio de PHP.

En Linux: `sudo apt install php-cli php-mysql mariadb-server`

Verificá que PHP tenga las extensiones necesarias:

```
php -m
```

Deben aparecer `pdo_mysql` y `openssl`. En XAMPP vienen activas por omisión.

### 2. Crear la base de datos

Abrí phpMyAdmin (`http://localhost/phpmyadmin`), creá una base llamada
`backupguard` con cotejamiento `utf8mb4_general_ci`, entrá a la pestaña
**Importar** y subí `database/schema.sql`.

Por línea de comandos:

```
mysql -u root -e "CREATE DATABASE backupguard CHARACTER SET utf8mb4;"
mysql -u root backupguard < database/schema.sql
```

### 3. Configurar

Copiá `includes/config.example.php` como `includes/config.php` y editalo.
Con XAMPP recién instalado alcanza con cambiar una sola línea:

```php
'encryption_key' => 'la frase larga que vos elijas',
```

Dejá `'modo_simulacion' => true` por ahora.

### 4. Arrancar

- **Windows**: doble clic en `iniciar.bat`
- **Linux / macOS**: `./iniciar.sh`

Se abre `http://localhost:8080`.

### 5. Crear tu usuario

Entrá a `http://localhost:8080/crear-admin.php`, creá el administrador y
después borrá ese archivo. La pantalla se cierra sola apenas existe un admin.

---

## Parte 2 — Conectar Oracle de verdad

Necesario solo cuando quieras que RMAN se ejecute realmente.

### 1. Tener una base Oracle

**Oracle Database XE** (Express Edition) es gratuita y suficiente. Instalada
localmente, el service name suele ser `XEPDB1` y el puerto `1521`.

Después de instalarla, conviene ponerla en ARCHIVELOG para poder demostrar la
estrategia completa (BackupGuard **no** hace este cambio: el enunciado exige
que la decisión sea del administrador):

```sql
-- Desde SQL*Plus como sysdba
SHUTDOWN IMMEDIATE;
STARTUP MOUNT;
ALTER DATABASE ARCHIVELOG;
ALTER DATABASE OPEN;
SELECT log_mode FROM v$database;
```

Dejá una segunda base o un momento en NOARCHIVELOG si querés mostrar la
advertencia preventiva funcionando.

### 2. Usuario para los respaldos

No uses SYS si podés evitarlo. Creá un usuario con el privilegio justo:

```sql
CREATE USER bgbackup IDENTIFIED BY "una_clave_segura";
GRANT SYSBACKUP TO bgbackup;
GRANT CREATE SESSION TO bgbackup;
```

Al registrarlo en BackupGuard, dejá marcada la casilla de conexión
privilegiada.

### 3. Habilitar oci8 en PHP

Sin `oci8`, BackupGuard no puede leer el modo de archivado ni los tablespaces
(todo lo demás sigue funcionando).

**Windows con XAMPP:**

1. Descargá **Oracle Instant Client Basic** para Windows x64 y descomprimilo
   en, por ejemplo, `C:\instantclient_21`.
2. Agregá esa carpeta al `PATH` del sistema y reiniciá la consola.
3. Descargá el DLL de `php_oci8_19.dll` correspondiente a tu versión de PHP
   (mirá `php -i | findstr "Thread Safety"` para saber si necesitás TS o NTS)
   y copialo a `C:\xampp\php\ext`.
4. En `C:\xampp\php\php.ini`, agregá:
   ```
   extension=oci8_19
   ```
5. Verificá con `php -m | findstr oci8`.

**Linux:** instalá Instant Client y luego
`sudo pecl install oci8` seguido de `extension=oci8.so` en el `php.ini`.

### 4. Apuntar a rman y a tnsnames

En `includes/config.php`:

```php
'rman_bin' => 'C:\\app\\TU_USUARIO\\product\\21c\\dbhomeXE\\bin\\rman.exe',
'oracle_tns_admin' => 'C:\\app\\TU_USUARIO\\product\\21c\\homes\\OraDB21Home1\\network\\admin',
'modo_simulacion' => false,
```

Comprobá que rman responde antes de seguir:

```
rman target /
```

### 5. Registrar la base

En **Bases de datos**, registrala con su alias TNS (o host/puerto/service name)
y presioná **Verificar**. Si la conexión funciona, la herramienta muestra el
modo de archivado, los tablespaces, los datafiles y el uso de la Fast Recovery
Area, y a partir de ahí aparecen las advertencias y recomendaciones.

---

## Parte 3 — Automatización

El runner hace una pasada y sale. El planificador del sistema lo llama cada
pocos minutos.

Probá primero sin ejecutar nada:

```
php scripts/runner.php --dry-run
```

**Windows:** ejecutá `scripts\instalar-tarea-windows.bat` como administrador.
Registra la tarea "BackupGuard Runner" cada 5 minutos. Ajustá la ruta de
`php.exe` dentro del archivo si no usás XAMPP.

**Linux:** ver `scripts/crontab-ejemplo.txt`.

**Oracle Scheduler:** si preferís que la programación viva dentro de Oracle,
creá un job de tipo `EXECUTABLE` que invoque `php` con la ruta del runner.

La computadora tiene que estar encendida a la hora programada. Para la
demostración conviene programar una estrategia a pocos minutos en el futuro y
dejar que el runner la tome solo.

---

## Problemas comunes

| Síntoma | Causa y solución |
|---|---|
| `Falta includes/config.php` | No copiaste el archivo de ejemplo |
| `SQLSTATE[HY000] [1045] Access denied` | Usuario o contraseña de MySQL incorrectos en `config.php` |
| `SQLSTATE[HY000] [2002]` | MySQL no está arrancado (panel de XAMPP) |
| `La extensión oci8 no está habilitada` | Ver Parte 2, paso 3. Mientras tanto, dejá `modo_simulacion => true` |
| `ORA-12541: TNS no listener` | El listener de Oracle está apagado: `lsnrctl start` |
| `ORA-01031: insufficient privileges` | Al usuario le falta SYSBACKUP o SYSDBA |
| `BackupGuard solo acepta conexiones desde esta misma computadora` | Estás entrando desde otro equipo; cambiá `solo_local` si de verdad lo necesitás |
| El runner no ejecuta nada | La estrategia debe estar **activa** y con script **aprobado**; revisá `--dry-run` |

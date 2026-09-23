# BackupGuard

Herramienta para la gestión de estrategias de respaldo de bases de datos Oracle,
usando **RMAN** como motor de ejecución.

Curso EIF402 — Administración de Bases de Datos · Universidad Nacional · II ciclo 2026

El propósito no es ejecutar scripts RMAN, sino administrar **estrategias**: definir
qué respaldar, cómo respaldarlo y cuándo, generar el script correspondiente,
programarlo, ejecutarlo y conservar evidencia de lo ocurrido. Es un control
preventivo sobre los riesgos de disponibilidad e integridad de la información.

```
Necesidad → QUÉ · CÓMO · CUÁNDO → Validación → Script RMAN → Aprobación
          → Programación → Ejecución → Evidencia → Alertas
```

## Qué hace

- **Registra bases Oracle** y detecta su modo de archivado (ARCHIVELOG /
  NOARCHIVELOG) leyéndolo de `v$database`. Nunca lo modifica.
- **Construye estrategias** desde un formulario: alcance, tipo de respaldo,
  compresión, paralelismo, retención, frecuencia, ventana y destino.
- **Valida** la estrategia antes de generar nada, distinguiendo entre
  información, recomendación, advertencia y error bloqueante.
- **Genera el script RMAN** y lo muestra completo. Nada se ejecuta hasta que un
  administrador lo aprueba explícitamente.
- **Automatiza** la ejecución mediante un runner invocado por cron, Task
  Scheduler u Oracle Scheduler.
- **Registra evidencia** de cada ejecución: horas, duración, script exacto,
  salida de RMAN, errores, ubicación y tamaño.
- **Levanta alertas preventivas**: estrategias sin programar, sin aprobar,
  respaldos que no corrieron, fallos recientes, bases sin estrategia, y más.

## Aplicación local

BackupGuard se instala y se usa **en la misma computadora donde está Oracle**.
No se despliega en un hosting, por dos razones: no hay hosting gratuito que
ofrezca Oracle, y una herramienta que guarda credenciales con privilegio
SYSDBA y ejecuta RMAN sobre la base no debería estar expuesta en internet —
publicarla crearía un riesgo mayor que el que pretende controlar.

Por eso el servidor escucha solo en `127.0.0.1` y la aplicación rechaza
cualquier petición que no venga de la propia máquina (`solo_local` en la
configuración).

## Instalación rápida

Requiere PHP 8.1+ con `pdo_mysql` y `openssl`, y MySQL/MariaDB. Con **XAMPP**
ya viene todo. Para ejecutar RMAN de verdad hace falta además la extensión
`oci8` y Oracle Instant Client; sin ellos la herramienta funciona completa en
modo simulación.

1. **Base de datos.** Creá una base vacía llamada `backupguard` e importá
   `database/schema.sql` desde phpMyAdmin.

2. **Configuración.** Copiá `includes/config.example.php` a
   `includes/config.php`. Con XAMPP recién instalado solo hace falta cambiar
   `encryption_key` por una frase propia: con ella se cifran las contraseñas
   de Oracle.

3. **Arrancar.** Doble clic en `iniciar.bat` (Windows) o `./iniciar.sh`
   (Linux/macOS). Se abre `http://localhost:8080`.

4. **Primer usuario.** Entrá a `crear-admin.php`, creá el administrador y
   después borrá ese archivo.

5. **Modo de trabajo.** `modo_simulacion => true` deja todo funcionando sin
   Oracle: las ejecuciones se simulan y quedan marcadas como tales. Pasalo a
   `false` cuando tengas `oci8` y `rman` listos.

La guía completa —instalar Oracle XE, ponerlo en ARCHIVELOG, crear el usuario
de respaldos, habilitar `oci8`, programar la tarea— está en
[`docs/instalacion-local.md`](docs/instalacion-local.md).

## Automatización

El runner hace una pasada y sale; el planificador del sistema lo invoca cada
pocos minutos. La computadora tiene que estar encendida a la hora programada.

```bash
# Ver qué está pendiente sin ejecutar nada
php scripts/runner.php --dry-run

# Ejecutar lo que corresponda
php scripts/runner.php
```

- Linux: ver `scripts/crontab-ejemplo.txt`
- Windows: ejecutar `scripts/instalar-tarea-windows.bat` como administrador
- Oracle Scheduler: un job de tipo EXECUTABLE que llame a `scripts/runner.php`

## Roles

| Rol | Puede |
|---|---|
| `admin` | Todo: registrar bases, aprobar scripts, ejecutar respaldos |
| `operador` | Crear y editar estrategias, pero no aprobar ni ejecutar |
| `auditor` | Solo lectura de historial, evidencia y alertas |

Quien diseña la estrategia no es necesariamente quien la aprueba: esa
separación de funciones es parte del control preventivo.

## Estructura

```
iniciar.bat / iniciar.sh  Arranca la aplicación en http://localhost:8080
index.php                 Tablero: métricas, alertas y próximas ejecuciones
estrategias.php           Listado de estrategias
estrategia-form.php       Construcción de la estrategia (QUÉ · CÓMO · CUÁNDO)
estrategia-detalle.php    Validación, script RMAN, aprobación y ejecución
bases-datos.php           Registro de bases Oracle y detección de archivado
historial.php             Historial de ejecuciones
ejecucion-detalle.php     Evidencia completa de una ejecución
alertas.php               Alertas preventivas vigentes

includes/
  RmanBuilder.php         Traduce la estrategia a un script RMAN + validaciones
  Programacion.php        Cálculo de próximas ejecuciones y ventanas
  Ejecutor.php            Corre RMAN, clasifica el resultado y guarda evidencia
  Alertas.php             Motor de control preventivo
  EstrategiaRepository.php  Acceso a datos
  oracle.php              Conexión OCI8 y lectura de contexto
  crypto.php              Cifrado AES-256-GCM de contraseñas Oracle
  auth.php, db.php, ui.php, header.php, navbar.php, footer.php

scripts/runner.php        Motor de automatización (cron / Task Scheduler)
database/schema.sql       Esquema completo
docs/                     Instalación local y mapeo de requerimientos
```

## Seguridad

- Contraseñas de usuarios con `password_hash`; contraseñas de Oracle cifradas
  con AES-256-GCM.
- Todas las consultas usan sentencias preparadas.
- Formularios que modifican datos protegidos con token CSRF.
- Toda acción relevante queda en la tabla `bitacora`.
- La aplicación solo responde a peticiones locales (`127.0.0.1`).
- `includes/config.php` y `storage/` están fuera de git.

Al ejecutar RMAN, la contraseña viaja en la línea de comandos y el cmdfile se
borra apenas termina. En un despliegue real conviene usar un wallet de Oracle.

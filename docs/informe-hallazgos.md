# Informe de hallazgos — Verificación de BackupGuard

Curso EIF402 — Administración de Bases de Datos · Universidad Nacional · II ciclo 2026
Verificación realizada: 2026-09-24, sobre el commit `1d9f943` (rama `main`).

## 1. Resumen ejecutivo

BackupGuard se puso a correr localmente (PHP 8.3.28 + MySQL 8.0, ambos ya instalados
en esta máquina) y se sometió a una simulación con dos bases ficticias, **XUNA** y
**XCCSS**, con estrategias, aprobaciones, ejecuciones (éxito y fallo controlado) y
automatización real vía `scripts/runner.php`. El detalle paso a paso de esa siembra
está en [`simulacion-xuna-xccss.md`](simulacion-xuna-xccss.md).

**Veredicto general: el prototipo cumple con lo pedido en el enunciado.** Implementa
las tres dimensiones QUÉ/CÓMO/CUÁNDO, genera y explica el script RMAN, exige
aprobación antes de ejecutar, automatiza vía un runner externo, guarda evidencia
completa y evalúa diez reglas de alertas preventivas. La decisión de operarlo de
forma local — en vez de un hosting — está técnicamente justificada y no es una
limitación que reduzca el alcance del proyecto: es, de hecho, la única opción
coherente con el propio riesgo que la herramienta pretende controlar (ver sección 6).

Lo que sí se encontró son brechas puntuales, ninguna bloqueante para la defensa,
detalladas en las secciones 4 y 5.

## 2. Qué se verificó y cómo

- Lectura completa del código (`includes/*.php`, todas las vistas, `scripts/runner.php`,
  `database/schema.sql`) contra los dos documentos fuente del curso: la guía teórica
  *"Estrategias de respaldo de bases de datos Oracle"* y el enunciado de la asignación
  *"Desarrollo de una Herramienta para la Gestión de Estrategias de Respaldo..."*.
- Arranque real del servidor (`php -S 127.0.0.1:8080`) con el `includes/config.php`
  que ya existía en el equipo (`modo_simulacion => true`).
- Siembra de datos vía las mismas clases que usa la aplicación
  (`EstrategiaRepository`, `RmanBuilder`, `Ejecutor`, `Alertas`) — no se insertó nada
  directamente por SQL salvo lo que la propia app no expone (marcar el modo de
  archivado, que en producción se obtiene con "Verificar conexión" contra Oracle real).
- Navegación autenticada de las ocho páginas principales vía `curl` con cookie de
  sesión, verificando `HTTP 200` y ausencia de `Fatal error` / `Parse error`.
- Ejecución real de `php scripts/runner.php --dry-run` y sin `--dry-run`, para
  confirmar que la automatización recoge una estrategia vencida sin intervención
  manual.
- Confirmación de que en esta misma máquina hay una instancia real de **Oracle
  Database XE 21c** instalada y con los servicios `OracleServiceXE` y
  `OracleOraDB21Home1TNSListener` activos, y que la extensión `oci8` de PHP ya está
  habilitada — es decir, el salto de "simulación" a "ejecución real contra Oracle"
  es alcanzable sin instalar software adicional (ver
  [`manual-oracle-xuna-xccss.md`](manual-oracle-xuna-xccss.md)).

## 3. Cumplimiento frente al enunciado

| Punto del enunciado | Estado | Evidencia |
|---|---|---|
| QUÉ: alcance + prioridad | Cumple | `estrategia-form.php`, campo `alcance` con base completa/tablespaces/datafiles; `prioridad` + `justificacion_prioridad` usados en las 4 estrategias sembradas |
| CÓMO: completo / incremental 0 / incremental 1 (dif./acum.) | Cumple | `RmanBuilder::lineasBackup()` traduce los 4 casos correctamente; verificado generando el script real de XUNA (completo comprimido) y de XUNA incremental diferencial |
| CUÁNDO: frecuencia, horario, ventana | Cumple | `Programacion.php` calcula `proxima_ejecucion` para diaria/semanal/mensual/única; se comprobó que tras una ejecución la fecha se recalculó correctamente (de hoy a "domingo siguiente" para la estrategia semanal) |
| ARCHIVELOG / NOARCHIVELOG como caso especial | Cumple | Tres niveles distintos (advertencia, recomendación, error bloqueante) implementados en `RmanBuilder::validar()`; se disparó la advertencia real registrando XCCSS en NOARCHIVELOG |
| Interfaz de construcción de estrategia sin escribir RMAN a mano | Cumple | `estrategia-form.php` cubre los 4 bloques pedidos (información general, qué, cómo, cuándo, destino) |
| Flujo Configuración→Validación→Script→Aprobación→Programación→Ejecución | Cumple | Implementado explícitamente en `estrategia-detalle.php`; el script generado no se puede ejecutar sin `aprobado = 1` (`Ejecutor::ejecutar()` lo verifica) |
| Automatización (cron / Task Scheduler / Oracle Scheduler) | Cumple | `scripts/runner.php` es agnóstico del planificador; se probó con éxito de forma manual simulando lo que haría el Programador de tareas de Windows |
| Evidencia de ejecución (11 campos mínimos del enunciado) | Cumple | Tabla `ejecuciones` cubre los 11 campos pedidos; se comprobó con una ejecución exitosa y una fallida real |
| Distinguir exitoso / con advertencias / fallido | Cumple | `Ejecutor::clasificar()` no confía solo en el código de salida: revisa el log en busca de `RMAN-`/`ORA-`; se verificó forzando un fallo (`ORA-19809`) |
| Alertas preventivas (mínimo 9 del enunciado) | Cumple, con 10 reglas | Las 10 reglas de `Alertas.php` cubren y superan las 9 pedidas explícitamente en la sección 11 del enunciado; 6 de las 10 se dispararon con datos reales durante la simulación |
| No asumir éxito solo porque RMAN no falló | Cumple | Doble chequeo: log sin errores **y** archivos nuevos en el destino (`contarArchivos()`); si RMAN "termina bien" pero no deja archivos, la ejecución baja a `advertencia` |
| Administrador revisa el script antes de aprobar | Cumple | El script se muestra completo en `estrategia-detalle.php`; existe además `explicarTraduccion()`, que traduce cada opción de la interfaz a la línea RMAN correspondiente — esto va más allá de lo mínimo pedido |
| No modificar bases de producción / decisión de ARCHIVELOG queda en el DBA | Cumple | `oracle.php` solo hace `SELECT`; no hay una sola sentencia `ALTER DATABASE` en todo el código |
| Separación de funciones (quién diseña ≠ quién aprueba) | Cumple | Tres roles (`admin`/`operador`/`auditor`) aplicados en cada controlador, no solo declarados |
| Entregable "prototipo funcional" | Cumple | Las 8 páginas cargaron sin error con datos reales de extremo a extremo |
| Entregable "evidencia" (éxito, fallo, historial, alerta, recomendación) | Cumple | Los 5 tipos de evidencia pedidos en la sección 13.4 del enunciado se generaron y quedaron documentados en `simulacion-xuna-xccss.md` |
| Recuperación (mencionada en el documento teórico, sección 6) | Cumple parcialmente | El script incluye `RESTORE DATABASE VALIDATE` (prueba de restaurabilidad), pero no hay un flujo de **recuperación real** (un `RESTORE`/`RECOVER` que efectivamente traiga la base de vuelta). Es una laguna menor: el enunciado pide "considerar la posibilidad" de recuperación, no necesariamente implementarla, y el propio documento teórico la deja como componente "puede incorporar" (opcional) |

## 4. Debilidades encontradas

Ordenadas de mayor a menor relevancia para la defensa del proyecto.

1. **`storage/` no está en `.gitignore`.** El archivo actual solo excluye
   `includes/config.php` y `.idea/`. El propio `README.md` afirma que
   *"`includes/config.php` y `storage/` están fuera de git"*, pero `storage/` no
   aparece en `.gitignore`. Hoy no es un problema porque la carpeta todavía no
   existe (no se ha ejecutado RMAN real), pero en cuanto se use
   `modo_simulacion => false`, esa carpeta va a contener `cmdfile` con la
   contraseña de Oracle en texto plano (aunque se borran al terminar, un fallo a
   mitad de ejecución los dejaría en disco) y logs de `rman` con nombres de
   objetos internos. Si alguien hace `git add -A` — como ya ocurrió una vez en
   este repositorio ("Add files via upload") — ese contenido podría subirse.
   **Corrección de una línea**: agregar `storage/` a `.gitignore`.

2. **Sin límite de intentos de login.** `login.php` no implementa *throttling* ni
   bloqueo tras intentos fallidos. Dado que la app guarda contraseñas Oracle con
   privilegio SYSDBA/SYSBACKUP, un acceso no autorizado al `localhost` (por
   ejemplo, otro usuario de la misma máquina, o un script malicioso corriendo
   localmente) podría intentar fuerza bruta sin restricción. El riesgo es bajo
   porque `solo_local` ya limita el vector a la propia máquina, pero es una
   mejora barata.

3. **Cookies de sesión sin flags explícitos.** No se llama a
   `session_set_cookie_params()` antes de `session_start()`, así que la cookie de
   sesión no fuerza `HttpOnly`/`SameSite=Strict` de forma explícita (depende del
   `php.ini` del entorno). En un servidor que solo escucha en `127.0.0.1` el
   impacto es marginal, pero es una buena práctica de bajo costo.

4. **La ventana de simulación de fallo es la única fuente de "fallido" en
   `modo_simulacion`.** `Ejecutor::correrSimulado()` solo produce un fallo cuando
   se pasa `forzarFallo = true` explícitamente; no hay manera de que el runner
   automático produzca fallos aleatorios/realistas en modo simulación. Esto está
   bien para demostrar el flujo, pero si la demo quiere mostrar que las alertas
   `EJECUCION_FALLIDA` / `NO_EJECUTADO` reaccionan solas frente a una falla no
   provocada manualmente, hay que orquestarlo a mano (como se hizo en esta
   verificación).

5. **`RmanBuilder::validar()` no valida `paralelismo` contra `retencion_dias` ni
   contra el tamaño esperado del respaldo** — es una validación de forma, no de
   fondo. No es un defecto respecto del enunciado (que no lo pide), pero conviene
   mencionarlo si el profesor pregunta por los límites del validador.

6. **No hay pruebas automatizadas** (unit tests) para `RmanBuilder`, `Programacion`
   o `Ejecutor::clasificar()`, que son las piezas con más lógica de negocio y las
   que más beneficio tendrían de una prueba automatizada (por ejemplo, la regex
   de `clasificar()` que decide fallido/advertencia/exitoso). Para un prototipo de
   curso es aceptable, pero es la primera mejora técnica recomendable si el
   proyecto continúa.

7. **Nota técnica para la defensa oral**: el modo `ARCHIVELOG`/`NOARCHIVELOG` en
   Oracle multitenant (12c en adelante, incluyendo XE 21c) es una propiedad del
   **CDB completo**, no de cada PDB por separado. La simulación registró XUNA en
   ARCHIVELOG y XCCSS en NOARCHIVELOG *dentro de BackupGuard* para poder mostrar
   las dos ramas de la advertencia a la vez, pero eso no es un estado que dos PDBs
   del mismo CDB puedan tener simultáneamente en un Oracle real. El detalle de
   cómo resolver esto para una demo real está explicado en la sección "Nota sobre
   ARCHIVELOG y multitenant" de
   [`manual-oracle-xuna-xccss.md`](manual-oracle-xuna-xccss.md). No es un defecto
   de BackupGuard — la herramienta lee lo que Oracle reporta, correctamente —
   sino algo a tener claro para no llevarse una sorpresa en la demostración.

## 5. Amenazas y riesgos (dado el despliegue local)

| Riesgo | Análisis |
|---|---|
| **"La computadora tiene que estar encendida a la hora programada"** (lo dice el propio README) | Es la limitación más real del enfoque local: si el equipo se apaga, se suspende o pierde energía a la hora programada, el respaldo simplemente no corre, y no hay una segunda instancia que lo cubra. La alerta `NO_EJECUTADO` lo detecta *después*, pero no lo previene. Mitigación de bajo costo: configurar el Programador de tareas de Windows con la opción "Ejecutar tarea tan pronto como sea posible después de un inicio programado perdido". |
| **Todo el respaldo vive en el mismo disco/red que la base** (a menos que el DBA configure `destino` explícitamente a otro volumen) | Viola la regla 3-2-1 de respaldos: si el disco donde vive Oracle falla, el respaldo generado en la misma máquina puede fallar con él. No es un defecto de BackupGuard — es responsabilidad del DBA al llenar el campo `destino` —, pero merece una advertencia explícita en la interfaz (hoy no existe ninguna). |
| **Credencial única de MySQL reutilizada** | La base `backupguard` corre bajo el mismo usuario `root` de MySQL que administra otras bases del equipo (se detectaron 8 bases adicionales en la misma instancia). Es una observación del entorno, no del proyecto en sí, pero significa que comprometer esa contraseña compromete BackupGuard también. Recomendable, aunque sea fuera del alcance del curso: un usuario MySQL dedicado con privilegios solo sobre `backupguard`. |
| **Contraseña de Oracle en la línea de comandos de RMAN** | Reconocido explícitamente por el propio equipo en el `README.md` ("la contraseña viaja en la línea de comandos"). Es un riesgo real pero **correctamente documentado y aceptado como limitación conocida** en vez de ignorado — eso es exactamente la postura correcta para un prototipo académico. La mejora natural (wallet de Oracle) queda anotada para un despliegue real. |
| **Sin copia fuera de sitio (offsite)** | Consecuencia directa de operar 100% local. Es aceptable para el alcance del curso — el enunciado no pide continuidad ante desastre físico del sitio — pero si este prototipo se propusiera para producción real, sería el primer punto a resolver (por ejemplo, sincronizar el `destino` con almacenamiento en otra ubicación). |

## 6. ¿El enfoque local alcanza las metas del proyecto?

Sí, y con un matiz importante: **no es una limitación del proyecto, es una
decisión de diseño correcta dado su propio objeto de estudio.**

El enunciado pide reducir riesgos de **disponibilidad e integridad**. Una
herramienta que guarda credenciales SYSDBA/SYSBACKUP cifradas y que puede invocar
RMAN contra una base de producción es, en sí misma, un activo crítico. Exponerla
en un hosting público —gratuito o no— multiplicaría la superficie de ataque sobre
exactamente lo que se quiere proteger, y el curso no cuenta (correctamente) con
presupuesto para una alternativa Oracle Cloud paga. La combinación
`solo_local => true` + verificación de `REMOTE_ADDR` en cada request
(`exigirAccesoLocal()` en `includes/db.php`) es una mitigación real, no cosmética:
se comprobó en el código que se aplica a *todas* las páginas web y se excluye
correctamente para el runner CLI.

Las tres preguntas que el proyecto debe responder según el documento teórico
(qué, cómo, cuándo) y la cuarta capacidad exigida (demostrar que el respaldo se
hizo) se cumplen igual de completas en local que en cualquier otro despliegue: no
dependen de la red, dependen de la lógica de negocio, y esa lógica se verificó
funcionando de punta a punta durante esta simulación.

**Dónde sí hay una limitación real** es en la disponibilidad del *runner*: sin la
máquina encendida no hay automatización, y sin una segunda máquina no hay
redundancia. Eso es correcto señalarlo como una limitación explícita del alcance
del prototipo (ideal para la sección "riesgos asociados" del documento de
análisis que pide el enunciado), no como un defecto de implementación.

## 7. Mejoras recomendadas

**Antes de la entrega/demo (bajo costo, alto valor para la defensa):**
- Agregar `storage/` a `.gitignore`.
- Documentar explícitamente en el README la limitación de ARCHIVELOG por CDB
  (sección 4.7 de este informe) para no dejarla como una pregunta abierta en la
  defensa oral.
- Agregar una nota en la interfaz de `estrategia-form.php` cuando `destino` esté
  vacío o apunte al mismo volumen que Oracle, recordando la regla 3-2-1.

**Si el proyecto continúa después del curso:**
- Throttling de login + flags de cookie de sesión explícitos.
- Pruebas unitarias para `RmanBuilder::validar()`/`construir()` y
  `Ejecutor::clasificar()` (son las de mayor lógica y mayor riesgo de regresión).
- Wallet de Oracle en vez de contraseña en la línea de comandos de RMAN.
- Usuario MySQL dedicado con privilegios acotados a la base `backupguard`.
- Mecanismo de replicación del `destino` de respaldo hacia almacenamiento externo
  (aunque sea una carpeta de red o un bucket S3-compatible), para dejar de
  depender enteramente del disco local.

## 8. Conclusión

BackupGuard responde correctamente a la pregunta orientadora del enunciado: usa
RMAN como motor de ejecución y construye sobre él una capa de gestión —
estrategia, validación, aprobación, programación, evidencia y alertas— que
convierte "correr un script" en "administrar un riesgo". La verificación con
datos reales (XUNA/XCCSS) confirmó que ese ciclo funciona sin intervención manual
más allá de la aprobación inicial, que es exactamente el punto de control que el
enunciado exige mantener bajo criterio humano. Las brechas encontradas son
menores y corregibles en minutos; ninguna contradice el diseño ni el propósito
del prototipo.

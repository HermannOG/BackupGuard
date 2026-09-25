# Simulación realizada — bases XUNA y XCCSS

Curso EIF402 — Administración de Bases de Datos · Universidad Nacional · II ciclo 2026
Fecha de la simulación: 2026-09-24.

Este documento explica **cómo** se generó la evidencia usada en
[`informe-hallazgos.md`](informe-hallazgos.md): qué se sembró, por qué se eligieron
esos dos escenarios y qué resultado dio cada paso. Sirve como bitácora reproducible
de la verificación, no como parte del prototipo en sí.

## 1. Por qué dos bases y por qué esos nombres

El enunciado pide demostrar el ciclo completo (estrategia → script → aprobación →
ejecución → evidencia → alertas) y, en particular, las dos ramas de la advertencia de
archivado (sección 6 del documento teórico). Un solo escenario no alcanza para eso:
hace falta una base "buena" (ARCHIVELOG, producción) y una "de riesgo"
(NOARCHIVELOG, pruebas) para que el motor de alertas tenga algo que decir en ambas
direcciones.

Se usaron los nombres sugeridos por el usuario como referencia de contexto
institucional (Universidad Nacional / Caja Costarricense del Seguro Social), sin que
tengan relación real con esas instituciones — son simplemente dos identificadores
ficticios que permiten diferenciar visualmente los dos perfiles dentro de la interfaz:

| | XUNA | XCCSS |
|---|---|---|
| Perfil simulado | Sistema académico (matrícula y expedientes) | Copia de pruebas de un sistema de planillas |
| Ambiente | `produccion` | `pruebas` |
| Modo de archivado | `ARCHIVELOG` | `NOARCHIVELOG` |
| Prioridad de sus estrategias | alta | media / baja |
| Qué debía disparar | Recomendación de incluir archived redo logs; validación normal | Advertencia de recuperación limitada; bloqueo si se intentan incluir archivelogs |

## 2. Entorno usado

No se instaló nada nuevo: se usó lo que ya existía en la máquina.

- **PHP 8.3.28** (ZTS, WAMP) con `pdo_mysql`, `openssl`, `oci8` y `sqlite3` ya
  habilitados.
- **MySQL 8.0** corriendo como servicio de Windows (`MySQL80`), con la base
  `backupguard` ya creada y el esquema de `database/schema.sql` ya importado
  (tenía 1 usuario y 1 base registrada de una siembra anterior del propio equipo;
  esos datos no se tocaron).
- `includes/config.php` ya existía con `modo_simulacion => true`, que es
  justamente el modo recomendado por el `README.md` mientras no se conecta Oracle
  real.
- Se confirmó, además, que esta máquina tiene una instancia real de **Oracle
  Database XE 21c** instalada (`C:\app\isaac\product\21c\dbhomeXE`) con los
  servicios `OracleServiceXE` y `OracleOraDB21Home1TNSListener` activos. **No se
  usó para esta simulación** — se mantuvo todo en modo simulación a propósito,
  para no modificar una instancia de Oracle real sin necesidad — pero su
  existencia confirma que el salto a ejecución real es viable en esta misma
  máquina; el procedimiento para hacerlo está en
  [`manual-oracle-xuna-xccss.md`](manual-oracle-xuna-xccss.md).

## 3. Qué hace `modo_simulacion => true` exactamente

Es importante dejarlo explícito porque es la pieza que hace posible simular sin
Oracle: `Ejecutor::correrSimulado()` (en `includes/Ejecutor.php`) **no invoca
`rman.exe` en absoluto**. En su lugar:

1. Genera una salida de texto con el mismo formato que produciría RMAN real
   (`Recovery Manager: Release 21.0.0.0.0...`, `allocated channel: ch1`, etc.),
   rotulada explícitamente como `[BackupGuard] EJECUCIÓN SIMULADA`.
2. Si la estrategia tiene un `destino` en disco (no la Fast Recovery Area), escribe
   archivos "pieza" reales de ~64 KB, con un encabezado que dice claramente que no
   son un respaldo de Oracle. Esto existe para que `Ejecutor::contarArchivos()` —
   que es la comprobación de "la existencia del archivo es evidencia", pedida
   explícitamente por el enunciado — tenga algo real que contar, en vez de simular
   también esa verificación.
3. Clasifica el resultado con la misma función (`clasificar()`) que usaría en modo
   real, buscando patrones `RMAN-`/`ORA-` en el texto generado.

Es decir: **todo el camino después de la ejecución (clasificación, evidencia,
alertas, recálculo de programación) es código de producción real**; lo único
sustituido es la llamada al binario `rman`.

## 4. Paso a paso de la siembra

Se escribió un script PHP (`seed.php`, ejecutado por línea de comandos, no forma
parte del repositorio) que usa **las mismas clases que usa la aplicación web** —
`EstrategiaRepository`, `RmanBuilder` (indirectamente, vía `generarScript()`),
`Ejecutor` y `Alertas` — para que la siembra fuera indistinguible de datos
capturados a mano desde la interfaz.

### 4.1 Usuario de prueba

Se creó un usuario adicional `qa_simulacion` (rol `admin`, contraseña
`Simulacion2026!`) en vez de tocar la cuenta real que ya existía en la base
(`Tretybool`), para no interferir con las credenciales del equipo. Este usuario
puede eliminarse después de la demo con:

```sql
DELETE FROM usuarios WHERE nombre_usuario = 'qa_simulacion';
```

### 4.2 Registro de las bases

```
XUNA  → id=2, ambiente=produccion, tns_alias=XUNA,  usuario=bgbackup, AS SYSDBA=no
XCCSS → id=3, ambiente=pruebas,    tns_alias=XCCSS, usuario=bgbackup, AS SYSDBA=no
```

El `modo_archivado` se fijó directamente después de `guardarBase()`
(`ARCHIVELOG` para XUNA, `NOARCHIVELOG` para XCCSS), porque en un registro real ese
campo lo llena el botón **Verificar** de `bases-datos.php`, que necesita `oci8`
conectado a una instancia Oracle de verdad con esos alias TNS existiendo. Al no
haber una base Oracle real llamada `XUNA`/`XCCSS` en este equipo, ese paso se
simuló actualizando la columna directamente — es exactamente el mismo efecto que
tendría `refrescarModoArchivado()` si la conexión existiera.

### 4.3 Estrategias creadas

| # | Nombre | Base | Alcance | Tipo | Frecuencia | Prioridad |
|---|---|---|---|---|---|---|
| 1 | XUNA - Respaldo completo semanal | XUNA | base completa | completo, comprimido, 2 canales | semanal (domingo 23:00) | alta |
| 2 | XUNA - Incremental diario diferencial | XUNA | base completa | incremental nivel 1 diferencial | diaria (02:00) | alta |
| 3 | XCCSS - Respaldo completo diario (pruebas) | XCCSS | base completa | completo, sin comprimir | diaria (20:00) | media |
| 4 | XCCSS - Datafiles críticos (borrador) | XCCSS | datafiles (nº 4) | completo | — (sin programar, a propósito) | baja |

La combinación 1+2 reproduce literalmente el ejemplo del documento teórico
("respaldo incremental nivel 0 una vez por semana... respaldo incremental nivel 1
diariamente"), usando un completo comprimido como base semanal en lugar de un
nivel 0 explícito — ambas son formas válidas de establecer el punto de partida de
la cadena incremental, y se eligió el completo semanal porque además ejercita la
rama de compresión y de `retencion_dias` (14 días, con `DELETE OBSOLETE`) del
validador.

La estrategia 4 se dejó **intencionalmente incompleta** (sin `fecha_inicio`/`hora`,
sin generar ni aprobar script) para poder verificar que las reglas
`SIN_PROGRAMACION` y `SIN_APROBACION` del motor de alertas realmente se disparan
frente a datos reales, no solo en la lectura del código.

Las estrategias 1, 2 y 3 se generaron, aprobaron y quedaron con `aprobado = 1` vía
`EstrategiaRepository::generarScript()` + `aprobar()`.

### 4.4 Script RMAN generado (extracto real, estrategia 1 — XUNA completo semanal)

```
BACKUP AS COMPRESSED BACKUPSET DATABASE INCLUDE CURRENT CONTROLFILE
  TAG 'BG_XUNARESPALDOCOMPL_COMPLE'
  FORMAT 'C:\BackupGuard_Sim\XUNA\bg_%d_%T_%s_%p.bkp'
  PLUS ARCHIVELOG;
```

Esto confirma en la práctica —no solo leyendo el código— que
`RmanBuilder::lineasBackup()` combina correctamente en una sola sentencia: tipo de
respaldo, compresión, inclusión del control file, la etiqueta (`TAG`), el destino
(`FORMAT`) y los archived redo logs (`PLUS ARCHIVELOG`), en el orden que exige la
sintaxis de RMAN.

### 4.5 Ejecuciones (evidencia)

| Ejecución | Estrategia | Origen | Resultado | Detalle |
|---|---|---|---|---|
| #1 | XUNA #1 (completo semanal) | manual | **Exitoso** | 3 archivos simulados escritos en `C:\BackupGuard_Sim\XUNA` |
| #2 | XUNA #2 (incremental diferencial) | manual | **Exitoso** | 2 archivos simulados |
| #3 | XCCSS #1 (completo diario) | manual, `forzarFallo=true` | **Fallido** | `RMAN-03009` / `ORA-19809: limit exceeded for recovery files` — fallo controlado pedido explícitamente por el enunciado (sección 13.4) |
| #4 | XCCSS #1 (completo diario) | manual | **Exitoso** | segunda corrida, para mostrar el historial con una falla seguida de una corrección |
| #5 | XUNA #1 (completo semanal) | **programada** (recogida sola por el runner) | **Exitoso** | ver sección 5 |

Las ejecuciones #1 y #3 se verificaron también desde la interfaz web
(`ejecucion-detalle.php?id=3`), confirmando que la insignia "Fallido" y el mensaje
`ORA-19809` se muestran correctamente en pantalla, no solo en la base de datos.

## 5. Prueba de automatización real

Para no limitarse a ejecuciones manuales, se forzó el escenario que el runner debe
resolver solo: se puso `proxima_ejecucion` de la estrategia XUNA #1 diez minutos en
el pasado y se corrió el runner tal como lo haría el Programador de tareas de
Windows o `cron`:

```
$ php scripts/runner.php --dry-run
[...] PENDIENTE (dry-run): #1 "XUNA - Respaldo completo semanal" sobre XUNA
      programada para 2026-09-24 21:02:35

$ php scripts/runner.php
[...] Ejecutando #1 "XUNA - Respaldo completo semanal" sobre XUNA...
[...]   → EXITOSO (0s) — evidencia #5
[...] Alertas vigentes: 6 (críticas 1, advertencias 4, recomendaciones 0).
```

Después de esa corrida, `estrategias.proxima_ejecucion` para XUNA #1 pasó de
"vencida" a **2026-09-27 23:00:00** (el domingo siguiente), calculado solo por
`Programacion::proxima()` — sin ninguna intervención manual. Esto demuestra la
relación completa que pide el enunciado:
**Estrategia → Programación → Script RMAN → Ejecución → Evidencia**, corriendo de
punta a punta sin que un humano dispare la ejecución.

## 6. Alertas resultantes

Con los datos anteriores, `Alertas::evaluar()` (la misma que corre en cada carga del
tablero y en cada pasada del runner) produjo **6 alertas vigentes**:

| Código | Severidad | Origen |
|---|---|---|
| `EJECUCION_FALLIDA` | crítica | la ejecución #3 forzada sobre XCCSS |
| `NOARCHIVELOG` | advertencia | XCCSS registrada en NOARCHIVELOG |
| `SIN_PROGRAMACION` | advertencia | estrategia 4 (borrador), sin `fecha_inicio`/`hora` |
| `SIN_APROBACION` | advertencia | estrategia 4 (borrador), sin script generado |
| `SIN_ESTRATEGIA` | advertencia | una base preexistente ("Tech", del equipo, no de esta simulación) sin ninguna estrategia |
| `SIN_CHEQUEO` | información | la misma base "Tech", sin verificación en más de 7 días |

Las dos últimas confirman algo útil: el motor de alertas también detectó
correctamente una condición de riesgo en datos que **no** fueron parte de esta
siembra — es decir, no está de alguna forma "cableado" para reaccionar solo a
XUNA/XCCSS, sino que evalúa el estado real de toda la base de datos.

No se disparó `ARCHIVELOGS_FUERA` porque las estrategias sobre XUNA (la única base
en `ARCHIVELOG`) ya incluyen `incluir_archivelogs = 1`; tampoco `NO_EJECUTADO` ni
`SIN_RESPALDO_RECIENTE` porque, tras la corrida del runner, todas las estrategias
activas quedaron con una ejecución reciente y una próxima fecha calculada.

## 7. Verificación de la interfaz web

Con sesión iniciada como `qa_simulacion`, se solicitaron por HTTP las ocho páginas
principales y se revisó cada respuesta buscando errores PHP:

```
bases-datos.php                 -> 200, sin errores
estrategias.php                 -> 200, sin errores
estrategia-detalle.php?id=1     -> 200, sin errores (script de XUNA visible completo)
estrategia-detalle.php?id=3     -> 200, sin errores
historial.php                   -> 200, sin errores
ejecucion-detalle.php?id=3      -> 200, sin errores (evidencia del fallo controlado)
alertas.php                     -> 200, sin errores (6 alertas listadas)
estrategia-form.php             -> 200, sin errores
```

## 8. Limitaciones de esta simulación

- No se invocó `rman.exe` real ni se conectó a la instancia Oracle XE 21c
  instalada en el equipo: todo corrió en `modo_simulacion => true`, como indica el
  propio `README.md` para trabajar sin Oracle.
- El `modo_archivado` de XUNA y XCCSS se fijó manualmente en la base de datos de
  BackupGuard, en vez de leerse de un `v$database` real, porque no existen
  instancias Oracle reales con esos nombres. La sección 4.7 del
  [informe de hallazgos](informe-hallazgos.md) explica por qué, en un Oracle real
  con arquitectura multitenant, dos PDBs del mismo CDB no podrían tener
  simultáneamente modos de archivado distintos como se hizo aquí — y cómo
  resolverlo si se decide reproducir el escenario contra Oracle real (ver
  [`manual-oracle-xuna-xccss.md`](manual-oracle-xuna-xccss.md)).
- Los "archivos de respaldo" que aparecen en `C:\BackupGuard_Sim\XUNA` y
  `C:\BackupGuard_Sim\XCCSS` son marcadores de texto (~64 KB cada uno), no
  respaldos reales de Oracle. Pueden borrarse sin ningún impacto:
  ```
  rmdir /s /q C:\BackupGuard_Sim
  ```

## 9. Cómo reproducir o limpiar esta siembra

- **Reproducir**: el script `seed.php` usado es idempotente (borra su propia
  siembra anterior de XUNA/XCCSS antes de volver a crearla), así que puede
  correrse de nuevo sin duplicar datos.
- **Limpiar por completo**, dejando la base como estaba antes de esta
  verificación:
  ```sql
  DELETE FROM alertas WHERE base_datos_id IN (SELECT id FROM bases_datos WHERE nombre IN ('XUNA','XCCSS'));
  DELETE FROM ejecuciones WHERE estrategia_id IN (SELECT id FROM estrategias WHERE base_datos_id IN (SELECT id FROM bases_datos WHERE nombre IN ('XUNA','XCCSS')));
  DELETE FROM estrategias WHERE base_datos_id IN (SELECT id FROM bases_datos WHERE nombre IN ('XUNA','XCCSS'));
  DELETE FROM bases_datos WHERE nombre IN ('XUNA','XCCSS');
  DELETE FROM usuarios WHERE nombre_usuario = 'qa_simulacion';
  ```
  ```
  rmdir /s /q C:\BackupGuard_Sim
  ```

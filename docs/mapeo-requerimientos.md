# Mapeo de requerimientos → implementación

Cada punto del enunciado con el archivo donde se resuelve. Sirve para la
defensa del proyecto y para el documento de diseño.

## 2. Preguntas mínimas que el proyecto debe responder

| Pregunta | Dónde se responde |
|---|---|
| ¿Qué información debe respaldarse? | `estrategia-form.php` → bloque "Qué respaldar"; tabla `estrategias.alcance` + `estrategia_objetos` |
| ¿Qué prioridad tiene la información? | Campo `prioridad` + `justificacion_prioridad` |
| ¿Qué tipo de respaldo debe utilizarse? | Campo `tipo_respaldo` + `modalidad` |
| ¿Con qué frecuencia? | Campo `frecuencia`, `dias_semana`, `dia_mes` |
| ¿En qué horario? | Campos `fecha_inicio`, `hora`, `ventana_minutos` |
| ¿Cómo se automatizará? | `scripts/runner.php` + cron / Task Scheduler / Oracle Scheduler |
| ¿Cómo se comprueba que el respaldo fue realizado? | `Ejecutor::clasificar()` y `Ejecutor::contarArchivos()`; `verificar_respaldo` agrega `RESTORE ... VALIDATE` |
| ¿Cómo se registra si tuvo éxito o falló? | Tabla `ejecuciones`; `ejecucion-detalle.php` |
| ¿Qué riesgos se pretenden disminuir? | `Alertas.php` — cada regla apunta a un riesgo concreto |

## 3. Enfoque de control preventivo

| Mecanismo pedido | Implementación |
|---|---|
| Planificación de respaldos | `estrategia-form.php`, tabla `estrategias` |
| Automatización | `scripts/runner.php`, `Programacion::proxima()` |
| Selección del tipo de respaldo | `tipo_respaldo` / `modalidad` → `RmanBuilder::lineasBackup()` |
| Programación de ejecuciones | `Programacion.php`, campo `proxima_ejecucion` |
| Seguimiento de resultados | `historial.php`, `EstrategiaRepository::resumen()` |
| Generación de evidencia | Tabla `ejecuciones` (script, log, duración, tamaño) |
| Detección de fallos | `Ejecutor::clasificar()` — revisa el log, no solo el código de salida |
| Alertas ante incumplimientos | `Alertas.php` (10 reglas) |
| Conservación de historial | Tablas `ejecuciones` y `bitacora` |
| Verificación de respaldos | `verificar_respaldo` → `VALIDATE BACKUPSET ALL` + `RESTORE DATABASE VALIDATE` |

## 4–5. Modelo QUÉ – CÓMO – CUÁNDO

La traducción exacta de cada opción a instrucciones RMAN está documentada en el
encabezado de `includes/RmanBuilder.php` y se muestra al usuario en
`estrategia-detalle.php` mediante `RmanBuilder::explicarTraduccion()`.

| Elemento | Opción de la interfaz | RMAN generado |
|---|---|---|
| Base completa | alcance = base_completa | `BACKUP ... DATABASE` |
| Tablespaces | alcance = tablespaces | `BACKUP ... TABLESPACE a, b` |
| Datafiles | alcance = datafiles | `BACKUP ... DATAFILE 4, '/ruta.dbf'` |
| Control file | incluir_controlfile | `INCLUDE CURRENT CONTROLFILE` |
| SPFILE | incluir_spfile | `BACKUP SPFILE` |
| Archived redo logs | incluir_archivelogs | `PLUS ARCHIVELOG [DELETE INPUT]` |
| Completo | tipo = completo | `BACKUP AS BACKUPSET` |
| Incremental nivel 0 | tipo = incremental_0 | `BACKUP INCREMENTAL LEVEL 0` |
| Incremental nivel 1 diferencial | tipo = incremental_1, modalidad = diferencial | `BACKUP INCREMENTAL LEVEL 1` |
| Incremental nivel 1 acumulativo | tipo = incremental_1, modalidad = acumulativo | `BACKUP INCREMENTAL LEVEL 1 CUMULATIVE` |
| Compresión | comprimido | `AS COMPRESSED BACKUPSET` |
| Paralelismo | paralelismo = n | n × `ALLOCATE CHANNEL` |
| Retención | retencion_dias | `CONFIGURE RETENTION POLICY` + `DELETE OBSOLETE` |
| Verificación | verificar_respaldo | `VALIDATE BACKUPSET ALL`, `RESTORE ... VALIDATE` |
| Destino | destino | cláusula `FORMAT`; vacío = Fast Recovery Area |

## 6. ARCHIVELOG / NOARCHIVELOG

- Detección: `leerContextoOracle()` en `includes/oracle.php` consulta
  `v$database.log_mode`. El resultado se guarda en `bases_datos.modo_archivado`.
- Advertencia por NOARCHIVELOG: `RmanBuilder::validar()` y `bases-datos.php`,
  con el texto pedido por el enunciado.
- Recomendación por ARCHIVELOG: mismo lugar, nivel `recomendacion`.
- Regla dura: incluir archived redo logs en una base NOARCHIVELOG es un **error**
  que impide generar el script. El resto son avisos: la decisión queda con el
  administrador y la herramienta nunca cambia el modo de archivado.
- La interfaz distingue visualmente información / recomendación / advertencia /
  error mediante `aviso()` en `includes/ui.php`.

## 8. Flujo de construcción del script

Implementado en `estrategia-detalle.php`:

```
Configuración → Validación → Construcción → Visualización
             → Aprobación → Programación → Ejecución
```

Editar una estrategia anula la aprobación y borra el script guardado
(`EstrategiaRepository::guardar()`), para que nunca se ejecute un script que ya
no corresponde a la configuración vigente.

## 9. Automatización

`scripts/runner.php` consulta `pendientesDeEjecutar()` — estrategias activas,
aprobadas y con `proxima_ejecucion` vencida — las ejecuta y recalcula la
siguiente fecha. La relación Estrategia → Programación → Script → Ejecución
queda en las claves foráneas de `ejecuciones`.

## 10. Evidencia

Todos los campos pedidos están en la tabla `ejecuciones` y se muestran en
`ejecucion-detalle.php`. Los tres estados son `exitoso`, `advertencia` y
`fallido` (más `en_curso` mientras corre).

## 11. Alertas

| Código | Condición | Severidad |
|---|---|---|
| `NOARCHIVELOG` | Base en modo NOARCHIVELOG | advertencia |
| `SIN_CHEQUEO` | Más de 7 días sin verificar la base | información |
| `SIN_PROGRAMACION` | Estrategia activa sin próxima ejecución calculable | advertencia |
| `SIN_APROBACION` | Estrategia activa con script sin aprobar o sin generar | advertencia |
| `INACTIVA_PRODUCCION` | Estrategia inactiva sobre una base de producción | advertencia |
| `EJECUCION_FALLIDA` | Fallo en los últimos 7 días | crítica |
| `NO_EJECUTADO` | Programado hace más de 2 horas y no corrió | crítica |
| `SIN_RESPALDO_RECIENTE` | Prioridad alta sin respaldo en 48 horas | crítica |
| `SIN_ESTRATEGIA` | Base registrada sin ninguna estrategia | advertencia |
| `ARCHIVELOGS_FUERA` | Base ARCHIVELOG cuya estrategia no los incluye | recomendación |

La falta de espacio se detecta al verificar la base: `bases-datos.php` avisa
cuando la Fast Recovery Area supera el 85% de uso.

## 14. Consideraciones técnicas

| Condición | Cómo se cumple |
|---|---|
| Probar en desarrollo o pruebas | Campo `ambiente`; las bases de producción generan advertencia al validar |
| No modificar producción | La herramienta solo lee de Oracle; RMAN se ejecuta con script aprobado |
| Validar antes de generar o ejecutar | `RmanBuilder::validar()` bloquea la generación si hay errores |
| El administrador revisa el script | `estrategia-detalle.php` lo muestra completo antes de aprobar |
| Distinguir recomendación de acción automática | Cuatro niveles de aviso; nada se aplica solo |
| No asumir éxito porque RMAN no falló | `Ejecutor::clasificar()` revisa el log completo |
| La existencia del archivo es evidencia | `Ejecutor::contarArchivos()` degrada a advertencia si no hay archivos |
| Usar RMAN para verificar los respaldos | `VALIDATE BACKUPSET ALL` y `RESTORE ... VALIDATE` |
| Considerar la recuperación posterior | La verificación prueba restaurabilidad; el modo de archivado condiciona la estrategia |

## 16. Pregunta orientadora

> ¿Cómo puede una herramienta de gestión de estrategias de respaldo utilizar RMAN
> para establecer controles preventivos que reduzcan los riesgos de
> disponibilidad e integridad?

La respuesta del prototipo: separando la **estrategia** (decisión) de la
**ejecución** (RMAN). La estrategia se declara, se valida, se traduce a un
script auditable, se aprueba, se programa y se ejecuta sola; cada corrida deja
evidencia y el sistema vigila de forma continua las condiciones que harían que
esa estrategia dejara de proteger. El riesgo baja no porque se ejecute RMAN,
sino porque el respaldo deja de depender de que alguien se acuerde, y porque el
sistema avisa cuando algo dejó de cumplirse.

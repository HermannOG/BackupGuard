@echo off
REM Registra el runner de BackupGuard en el Task Scheduler de Windows,
REM repitiendo cada 5 minutos. Ejecutalo como administrador.
REM Ajusta las dos rutas de abajo a tu instalacion.

set PHP_EXE=C:\xampp\php\php.exe
set RUNNER=%~dp0runner.php

schtasks /Create /SC MINUTE /MO 5 /TN "BackupGuard Runner" ^
  /TR "\"%PHP_EXE%\" \"%RUNNER%\"" /F

echo.
echo Tarea registrada. Para verla:   schtasks /Query /TN "BackupGuard Runner"
echo Para eliminarla:                schtasks /Delete /TN "BackupGuard Runner" /F
pause

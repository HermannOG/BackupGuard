@echo off
REM Arranca BackupGuard en esta computadora, en http://localhost:8080
REM Si usas XAMPP, la ruta de php.exe suele ser C:\xampp\php\php.exe

setlocal
set PHP_EXE=php
where /q php || set PHP_EXE=C:\xampp\php\php.exe

if not exist "%~dp0includes\config.php" (
  echo.
  echo  Falta includes\config.php
  echo  Copia includes\config.example.php como includes\config.php
  echo  y coloca ahi tus credenciales antes de continuar.
  echo.
  pause
  exit /b 1
)

echo.
echo  BackupGuard corriendo en http://localhost:8080
echo  Dejá esta ventana abierta. Ctrl+C para detener.
echo.
start "" http://localhost:8080
"%PHP_EXE%" -S 127.0.0.1:8080 -t "%~dp0"

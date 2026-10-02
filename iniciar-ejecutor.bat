@echo off
REM Arranca el EJECUTOR de BackupGuard: un ciclo infinito que revisa el
REM catalogo de estrategias cada 30 segundos y corre RMAN cuando es el dia
REM y la hora. Deja esta ventana abierta mientras quieras que se ejecuten
REM los respaldos programados. Ctrl+C lo detiene.

setlocal
set PHP_EXE=php
where /q php || set PHP_EXE=C:\xampp\php\php.exe

title BackupGuard - Ejecutor
"%PHP_EXE%" "%~dp0scripts\runner.php" --loop --intervalo=30
pause

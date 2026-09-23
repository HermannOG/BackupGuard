#!/usr/bin/env bash
# Arranca BackupGuard en esta computadora, en http://localhost:8080
set -e
cd "$(dirname "$0")"

if [ ! -f includes/config.php ]; then
  echo "Falta includes/config.php"
  echo "Copiá includes/config.example.php como includes/config.php y completá tus credenciales."
  exit 1
fi

echo "BackupGuard corriendo en http://localhost:8080  (Ctrl+C para detener)"
exec php -S 127.0.0.1:8080 -t .

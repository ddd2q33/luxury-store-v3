#!/usr/bin/env bash
# ============================================================
# Luxury v3 - backup (servidor Linux de produccion / Namecheap)
# Requisitos: bash, mysqldump, tar + gzip (los trae cPanel/VPS).
#
# Cron (cPanel > Cron Jobs o crontab del VPS), diario 02:30 UTC:
#   30 2 * * * /home/USUARIO/backups/luxury.sh >/dev/null 2>&1
#
# Config: variables abajo o archivo /home/USUARIO/backups/luxury.env
#   (DB_USER y DB_PASS van ahi; chmod 600). El dump se hace con
#   --single-transaction: no bloquea la web mientras se respalda.
#
# Rotacion identica al backup.ps1 local: daily 7 / weekly 4 / monthly 12.
# La copia externa (rclone) es opcional: es tu pata "off-site" del 3-2-1.
# ============================================================

set -euo pipefail

BASE=${BASE:-"$HOME/backups"}                 # SIEMPRE fuera de public_html
APP_ROOT=${APP_ROOT:-"$HOME/public_html/luxuryv3"}
DB_NAME=${DB_NAME:-luxury}
DB_USER=${DB_USER:-}
DB_PASS=${DB_PASS:-}
INCLUDE_CODE=${INCLUDE_CODE:-1}               # 1 = empaqueta codigo de la app
GOOGLE_DRIVE_REMOTE=${GOOGLE_DRIVE_REMOTE:-}  # ej.: gdrive:luxury-backups (copia en Google Drive)
EXTERNAL_SYNC=${EXTERNAL_SYNC:-}              # otro destino rclone, ej.: s3:bucket/luxury

ENV_FILE="$BASE/luxury.env"
if [ -f "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  . "$ENV_FILE"
fi
: "${DB_USER:?Defina DB_USER en luxury.env}"

DAILY="$BASE/daily"
WEEKLY="$BASE/weekly"
MONTHLY="$BASE/monthly"
mkdir -p "$DAILY" "$WEEKLY" "$MONTHLY"

log(){ echo "$(date '+%Y-%m-%d %H:%M:%S')  $*" | tee -a "$BASE/backup.log"; }

stamp=$(date +%Y%m%d_%H%M%S)

# 1) Base de datos
TMP_SQL="$BASE/luxury_latest.sql"
PASSARG=""
[ -n "$DB_PASS" ] && PASSARG="-p$DB_PASS"
mysqldump --default-character-set=utf8mb4 --single-transaction --routines --triggers \
  -u"$DB_USER" $PASSARG "$DB_NAME" > "$TMP_SQL"
if ! grep -q 'Dump completed' "$TMP_SQL"; then
  log 'ERROR: dump incompleto (falta "Dump completed")'
  exit 1
fi
tar -czf "$DAILY/luxury_${stamp}_db.sql.tar.gz" -C "$BASE" luxury_latest.sql
rm -f "$TMP_SQL"
log "OK db luxury_${stamp}_db.sql.tar.gz ($(du -h "$DAILY/luxury_${stamp}_db.sql.tar.gz" | cut -f1))"

# 2) Uploads (avatares, facturas/recibos)
tar -czf "$DAILY/luxury_${stamp}_uploads.tar.gz" -C "$APP_ROOT" uploads
log 'OK uploads'

# 3) Codigo (sin uploads, backups, .git ni logs)
if [ "$INCLUDE_CODE" = "1" ]; then
  tar -czf "$DAILY/luxury_${stamp}_code.tar.gz" -C "$APP_ROOT" \
    --exclude='./uploads' --exclude='./uploads/*' \
    --exclude='./backups' --exclude='./backups/*' \
    --exclude='./.git' --exclude='./.git/*' \
    --exclude='./*.log' .
  log 'OK code'
fi

# 4) Promociones semanales / mensuales
if [ "$(date +%u)" = "7" ]; then
  cp -f "$DAILY"/luxury_*.tar.gz "$WEEKLY/"
  log 'Promocion -> weekly'
fi
if [ "$(date +%d)" = "01" ]; then
  cp -f "$DAILY"/luxury_*.tar.gz "$MONTHLY/"
  log 'Promocion -> monthly'
fi

# 5) Retencion: 7 dias / 4 semanas / 12 meses
find "$DAILY"   -name 'luxury_*' -mtime +7   -delete 2>/dev/null || true
find "$WEEKLY"  -name 'luxury_*' -mtime +28  -delete 2>/dev/null || true
find "$MONTHLY" -name 'luxury_*' -mtime +365 -delete 2>/dev/null || true
log 'Retencion OK'

# 6) Copia a Google Drive (off-site; requiere rclone autenticado)
if [ -n "$GOOGLE_DRIVE_REMOTE" ]; then
  if command -v rclone >/dev/null 2>&1; then
    if rclone copy "$DAILY" "$GOOGLE_DRIVE_REMOTE" --transfers 4 -q >> "$BASE/backup.log" 2>&1; then
      log 'OK Drive'
    else
      log 'WARN: Drive sync fallo'
    fi
  else
    log 'WARN: GOOGLE_DRIVE_REMOTE definido pero rclone no esta instalado'
  fi
fi

# 7) Otro destino externo opcional
if [ -n "$EXTERNAL_SYNC" ]; then
  if rclone sync "$DAILY" "$EXTERNAL_SYNC" >> "$BASE/backup.log" 2>&1; then
    log 'Sync externo OK'
  else
    log 'WARN: rclone fallo (se reintenta el proximo backup)'
  fi
fi

log '== Backup completado =='
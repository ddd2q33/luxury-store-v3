# Scripts de operaciones (no forman parte de la app web)

Estos archivos **no deben ser accesibles por HTTP**. En producción, cópialos
**fuera de `public_html`**, igual que los backups:

```
/home/USUARIO/
├── public_html/          ← la app (sin scripts/)
└── backups/
    ├── luxury.sh         ← copia de scripts/backup.sh
    ├── luxury.php        ← copia de scripts/backup.php
    ├── luxury.env        ← credenciales DB (chmod 600)
    ├── daily/  weekly/  monthly/
    └── backup.log
```

- `backup.sh` — servidor Linux con shell: usa mysqldump + rotación
  7 diarias / 4 semanales / 12 mensuales + opción de copia a Drive (rclone).
- `backup.php` — hosting compartido sin shell (cPanel): mismo comportamiento
  con PDO + ZipArchive.

## Cron (cPanel → Cron Jobs)

```
30 2 * * * php /home/USUARIO/backups/luxury.php
30 2 * * * /home/USUARIO/backups/luxury.sh
```

## Restaurar una copia

```bash
# 1) descomprimir
tar -xzf daily/luxury_YYYYMMDD_HHMMSS_db.sql.tar.gz
# 2) importar
mysql -u USUARIO_luxury -p USUARIO_luxury < luxury_latest.sql
# 3) uploads
tar -xzf daily/luxury_YYYYMMDD_HHMMSS_uploads.tar.gz -C ~/public_html/luxuryv3
```

## Nota local (XAMPP / desarrollo)

En local los dumps también viven fuera del web root: `C:\xampp\backups\luxuryv3\`.
El `.htaccess` de esta carpeta es solo una capa adicional; no dependas de él.

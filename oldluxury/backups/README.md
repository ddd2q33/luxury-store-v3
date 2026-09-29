# backups/

Esta carpeta se mantiene vacía a propósito.

Los volcados de la base de datos **NO se guardan dentro del web root**
(serían descargables por HTTP si el servidor no aplica el `.htaccess`).

- **Local (XAMPP):** `C:\xampp\backups\luxuryv3\`
- **Producción:** `/home/USUARIO/backups/` (fuera de `public_html`)

Ver `scripts/README.md` para el proceso de backup automático y restauración.

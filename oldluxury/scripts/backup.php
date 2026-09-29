<?php
/**
 * Luxury v3 - backup por PHP (Namecheap/cPanel SIN acceso a shell).
 * Usalo si mysqldump no esta disponible. Hace el dump de la BD con PDO,
 * empaqueta uploads en zip y aplica la misma retencion que backup.ps1 local.
 *
 * Cron en cPanel (Cron Jobs → Nueva tarea):  30 2 * * *  php /home/USUARIO/backups/luxury.php
 * O sube este archivo a tu hosting y ejecutalo desde "Multi PHP Manager"/cron.
 * Log: backups/backup.log
 */

/* ==== CONFIG: edita ================================================ */
$cfg = [
    'base' => '/home/USUARIO/backups',                       // FUERA de public_html
    'app'  => '/home/USUARIO/public_html/luxuryv3',
    'db'   => [
        'host' => 'localhost',
        'name' => 'USUARIO_luxury',
        'user' => 'USUARIO_luxury',
        'pass' => 'CLAVE_DB',
    ],
    'ret_days'    => 7,    // dias a conservar en daily
    'ret_weeks'   => 28,   // dias a conservar en weekly
    'ret_months'  => 365,  // dias a conservar en monthly
];
/* ==== fin config =================================================== */

set_time_limit(600);
ini_set('memory_limit', '256M');

$base   = rtrim($cfg['base'], '/');
$daily  = "$base/daily";
$weekly = "$base/weekly";
$monthly = "$base/monthly";
foreach (array($base, $daily, $weekly, $monthly) as $d) {
    if (!is_dir($d)) { mkdir($d, 0755, true) or die("no pude crear $d"); }
}
$logf = "$base/backup.log";
function logl($m) { global $logf; file_put_contents($logf, date('Y-m-d H:i:s') . "  $m\n", FILE_APPEND); }

$stamp = date('Ymd_His');

try {
    logl('== Inicio backup ==');

    /* 1) Dump de la base de datos (gzip en vivo, sin mysqldump) */
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['pass']
    );
    $dbGz = gzopen("$daily/luxury_{$stamp}_db.sql.gz", 'w9');
    gzwrite($dbGz, "-- Luxury v3 dump $stamp\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = array();
    foreach ($pdo->query('SHOW TABLES') as $row) {
        $tables[] = array_values($row)[0];
    }
    foreach ($tables as $t) {
        gzwrite($dbGz, "\n-- TABLE $t\nDROP TABLE IF EXISTS `$t`;\n");
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        gzwrite($dbGz, array_values($create)[1] . ";\n");
        $rows = $pdo->query("SELECT * FROM `$t`");
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $vals = array();
            foreach ($row as $v) {
                $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
            }
            gzwrite($dbGz, "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n");
        }
    }
    gzwrite($dbGz, "\nSET FOREIGN_KEY_CHECKS=1;\n-- Dump completed on $stamp\n");
    gzclose($dbGz);
    $mbsize = round(filesize("$daily/luxury_{$stamp}_db.sql.gz") / 1048576, 2);
    logl("OK db luxury_{$stamp}_db.sql.gz ($mbsize MB)");

    /* 2) Uploads en zip */
    $zipPath = "$daily/luxury_{$stamp}_uploads.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('no se pudo crear el zip de uploads');
    }
    $uploadDir = $cfg['app'] . '/uploads';
    $prefixLen = strlen($cfg['app']) + 1;
    if (is_dir($uploadDir)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), $prefixLen));
            }
        }
    }
    $zip->close();
    logl('OK uploads');

    /* 3) Promociones semanales (domingo) / mensuales (dia 1) */
    foreach (glob("$daily/luxury_*") as $f) {
        if (date('N') == 7)        { @copy($f, "$weekly/" . basename($f)); }
        if (date('j') == 1)        { @copy($f, "$monthly/" . basename($f)); }
    }

    /* 4) Retencion */
    foreach (glob("$daily/luxury_*") as $f)   { if (time() - filemtime($f) > $cfg['ret_days'] * 86400)   @unlink($f); }
    foreach (glob("$weekly/luxury_*") as $f)  { if (time() - filemtime($f) > $cfg['ret_weeks'] * 86400)  @unlink($f); }
    foreach (glob("$monthly/luxury_*") as $f) { if (time() - filemtime($f) > $cfg['ret_months'] * 86400) @unlink($f); }
    logl('Retencion OK');

    logl('== Backup completado ==');
} catch (Throwable $e) {
    logl('ERROR: ' . $e->getMessage());
    exit(1);
}
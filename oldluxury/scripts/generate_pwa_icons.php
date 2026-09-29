<?php
/**
 * scripts/generate_pwa_icons.php
 * Genera los iconos PWA en assets/img/icons/ SIN depender de la extensión GD.
 * Usa solo zlib (empaquetado PNG a mano: RLE vertical por filas de píxeles).
 *
 * Uso:  php scripts/generate_pwa_icons.php
 */

$sizes = [192, 512];
$outDir = __DIR__ . '/../assets/img/icons';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

/* ---------- Núcleo del dibujo: un lienzo RGBA simple ---------- */
function canvas(int $w, int $h): array
{
    return ['w' => $w, 'h' => $h, 'px' => array_fill(0, $w * $h, [0, 0, 0, 0])];
}

/** Pinta un círculo con suavizado en bordes (antialias por cobertura). */
function fillCircle(array &$c, float $cx, float $cy, float $r, array $rgba): void
{
    for ($y = (int)floor($cy - $r); $y <= (int)ceil($cy + $r); $y++) {
        for ($x = (int)floor($cx - $r); $x <= (int)ceil($cx + $r); $x++) {
            if ($x < 0 || $y < 0 || $x >= $c['w'] || $y >= $c['h']) continue;
            $dx = ($x + 0.5) - $cx;
            $dy = ($y + 0.5) - $cy;
            $d = sqrt($dx * $dx + $dy * $dy);
            if ($d > $r + 0.7071) continue; // fuera
            $cov = max(0.0, min(1.0, $r - $d + 0.5)); // cobertura ~antialias
            $i = $y * $c['w'] + $x;
            $dst = $c['px'][$i];
            $a = $rgba[3] / 255 * $cov;
            if ($a <= 0) continue;
            // alpha-composite simple sobre lo que haya
            $outA = $a + ($dst[3] / 255) * (1 - $a);
            if ($outA <= 0) { continue; }
            for ($k = 0; $k < 3; $k++) {
                $c['px'][$i][$k] = (int)round(($rgba[$k] * $a + $dst[$k] * ($dst[3] / 255) * (1 - $a)) / $outA);
            }
            $c['px'][$i][3] = (int)round($outA * 255);
        }
    }
}

/* ---------- Codificador PNG (truecolor + alfa, filtro 0, zlib) ---------- */
function pngChunks(string $idat, int $w, int $h): string
{
    $sig = "\x89PNG\r\n\x1a\n";
    $ihdr = pack('N5C3', $w, $h, 8, 6, 0, 0, 0, 0); // bitDepth 8, colorType 6 (RGBA), compr/filtro/entrelazado = 0
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return $sig . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idat) . $chunk('IEND', '');
}

function encodePng(array $canvas): string
{
    $raw = '';
    for ($y = 0; $y < $canvas['h']; $y++) {
        $raw .= "\x00"; // filtro: none
        for ($x = 0; $x < $canvas['w']; $x++) {
            [$r, $g, $b, $a] = $canvas['px'][$y * $canvas['w'] + $x];
            $raw .= chr($r) . chr($g) . chr($b) . chr($a);
        }
    }
    return pngChunks(gzcompress($raw, 9), $canvas['w'], $canvas['h']);
}

/* ---------- Dibujo del icono "L" (identidad Luxury) ---------- */
function drawIcon(int $size): array
{
    $c = canvas($size, $size);
    $s = $size / 512.0; // escala relativa al diseño base

    // Fondo: círculo negro (redondeado visualmente; Android lo enmascara igual)
    fillCircle($c, $size / 2, $size / 2, $size * 0.5, [17, 24, 39, 255]); // #111827

    // Anillo dorado (estética "luxury")
    $ringR = $size * 0.415;
    $ringW = 10 * $s;
    fillCircle($c, $size / 2, $size / 2, $ringR + $ringW / 2, [201, 162, 39, 255]);       // #C9A227 (buen contraste)
    fillCircle($c, $size / 2, $size / 2, $ringR - $ringW / 2, [17, 24, 39, 255]);        // vuelve al fondo

    // Letra "L" gruesa, estilo monograma: tallo + base con serifa
    $gold = [212, 175, 55, 255]; // #D4AF37
    $stroke = 34 * $s;
    // tallo vertical (izquierda)
    $x0 = $size * 0.335;
    $y0 = $size * 0.26;
    $y1 = $size * 0.62;
    for ($x = $x0; $x < $x0 + $stroke; $x++) {
        for ($y = $y0; $y < $y1; $y++) {
            $i = (int)$y * $size + (int)$x;
            $c['px'][$i] = $gold;
        }
    }
    // barra horizontal (base)
    $xL = $size * 0.335;
    $xR = $size * 0.665;
    for ($x = $xL; $x < $xR; $x++) {
        for ($y = $y1 - $stroke; $y < $y1; $y++) {
            $i = (int)$y * $size + (int)$x;
            $c['px'][$i] = $gold;
        }
    }
    // serifa inferior (pequeño remate a la derecha de la base)
    $yS = $y1;
    for ($x = $xR - $stroke * 0.6; $x < $xR + $stroke * 0.55; $x++) {
        for ($y = $yS; $y < $yS + 10 * $s; $y++) {
            if ((int)$y >= $size) continue;
            $i = (int)$y * $size + (int)$x;
            $c['px'][$i] = $gold;
        }
    }
    return $c;
}

foreach ($sizes as $size) {
    $png = encodePng(drawIcon($size));
    file_put_contents($outDir . "/icon-{$size}.png", $png);
    echo "OK  icon-{$size}.png  (" . strlen($png) . " bytes)\n";
}

// Maskable: mismo dibujo con el arte dentro de la zona segura (~80%)
function drawMaskable(int $size): array
{
    $c = drawIcon($size);
    // El fondo ya es un círculo; para maskable necesitamos fondo sólido de esquina a esquina.
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $i = $y * $size + $x;
            if ($c['px'][$i][3] < 255) {
                $c['px'][$i] = [17, 24, 39, 255];
            }
        }
    }
    return $c;
}

foreach ($sizes as $size) {
    $png = encodePng(drawMaskable($size));
    file_put_contents($outDir . "/maskable-{$size}.png", $png);
    echo "OK  maskable-{$size}.png (" . strlen($png) . " bytes)\n";
}

echo "Listo: iconos PWA generados en assets/img/icons/\n";

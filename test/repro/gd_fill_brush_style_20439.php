<?php

foreach (['imagefilltoborder', 'imagesetbrush', 'imagesetstyle', 'imagefill'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}

$im = imagecreatetruecolor(10, 10);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$bd = imagecolorallocate($im, 255, 0, 0);
$fl = imagecolorallocate($im, 0, 255, 0);
imagefilledrectangle($im, 0, 0, 9, 9, $bg);
imagerectangle($im, 1, 1, 8, 8, $bd);
imagefilltoborder($im, 5, 5, $bd, $fl);
echo 'fill=', (int) ((imagecolorat($im, 5, 5) & 0xFFFFFF) === ($fl & 0xFFFFFF)), PHP_EOL;
echo 'border=', (int) ((imagecolorat($im, 1, 1) & 0xFFFFFF) === ($bd & 0xFFFFFF)), PHP_EOL;
echo 'outside=', (int) ((imagecolorat($im, 0, 0) & 0xFFFFFF) === ($bg & 0xFFFFFF)), PHP_EOL;

$styled = imagecreatetruecolor(20, 5);
imagealphablending($styled, false);
$bg = imagecolorallocate($styled, 0, 0, 0);
$a = imagecolorallocate($styled, 255, 0, 0);
imagefilledrectangle($styled, 0, 0, 19, 4, $bg);
imagesetstyle($styled, [$a, $a, IMG_COLOR_TRANSPARENT, IMG_COLOR_TRANSPARENT]);
imageline($styled, 0, 2, 19, 2, IMG_COLOR_STYLED);
$ink = 0;
$gap = 0;
for ($x = 0; $x < 8; ++$x) {
    if ((imagecolorat($styled, $x, 2) & 0xFFFFFF) === ($a & 0xFFFFFF)) {
        ++$ink;
    } else {
        ++$gap;
    }
}
echo 'style_ink=', (int) ($ink > 0), PHP_EOL;
echo 'style_gap=', (int) ($gap > 0), PHP_EOL;

$brush = imagecreatetruecolor(3, 3);
imagealphablending($brush, false);
$t = imagecolorallocate($brush, 0, 0, 0);
imagecolortransparent($brush, $t);
imagefilledrectangle($brush, 0, 0, 2, 2, $t);
$w = imagecolorallocate($brush, 255, 255, 255);
imagesetpixel($brush, 1, 1, $w);
$brushed = imagecreatetruecolor(15, 15);
imagealphablending($brushed, false);
$bg = imagecolorallocate($brushed, 0, 0, 0);
imagefilledrectangle($brushed, 0, 0, 14, 14, $bg);
imagesetbrush($brushed, $brush);
imageline($brushed, 2, 7, 12, 7, IMG_COLOR_BRUSHED);
$hits = 0;
for ($x = 0; $x < 15; ++$x) {
    for ($y = 0; $y < 15; ++$y) {
        if ((imagecolorat($brushed, $x, $y) & 0xFFFFFF) === 0xFFFFFF) {
            ++$hits;
        }
    }
}
echo 'brush_hits=', (int) ($hits > 0), PHP_EOL;
echo 'ok', PHP_EOL;

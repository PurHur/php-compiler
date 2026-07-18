<?php
/**
 * Repro for #20486 — imageloadfont / imagecolormatch surface.
 */
foreach (['imageloadfont', 'imagecolormatch', 'imagesetclip', 'imagegetclip', 'gd_info'] as $n) {
    echo $n, '=', function_exists($n) ? 'Y' : 'N', PHP_EOL;
}

$fontPath = __DIR__.'/../fixtures/gd/gh13082.gdf';
$font = imageloadfont($fontPath);
echo 'font=', ($font instanceof GdFont) ? 'GdFont' : var_export($font, true), PHP_EOL;

$im = imagecreatetruecolor(40, 20);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
echo 'string=', imagestring($im, $font, 2, 2, 'Hi', $fg) ? '1' : '0', PHP_EOL;

$ima = imagecreatetruecolor(8, 8);
$c = imagecolorallocate($ima, 10, 20, 30);
imagefilledrectangle($ima, 0, 0, 7, 7, $c);
$imb = imagecreate(8, 8);
imagecolorallocate($imb, 0, 0, 100);
echo 'match=', imagecolormatch($ima, $imb) ? '1' : '0', PHP_EOL;
$c = imagecolorsforindex($imb, 0);
echo 'r=', $c['red'], ' g=', $c['green'], ' b=', $c['blue'], PHP_EOL;

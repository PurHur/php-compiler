<?php
declare(strict_types=1);

$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
echo function_exists('imagettftext') ? "ttf=1\n" : "ttf=0\n";
echo function_exists('imagettfbbox') ? "bbox=1\n" : "bbox=0\n";
if (!function_exists('imagettftext') || !is_readable($font)) {
    echo "skip\n";
    return;
}

$im = imagecreatetruecolor(80, 40);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 79, 39, $white);

$bbox = imagettfbbox(12, 0, $font, 'Hi');
echo is_array($bbox) ? 'bbox_n='.count($bbox)."\n" : "bbox_fail\n";

$drawn = imagettftext($im, 12, 0, 5, 25, $black, $font, 'Hi');
echo is_array($drawn) ? "draw=1\n" : "draw=0\n";

$ink = 0;
for ($y = 10; $y < 30; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if (imagecolorat($im, $x, $y) !== $white) {
            ++$ink;
        }
    }
}
echo $ink > 0 ? "ink=1\n" : "ink=0\n";

$bad = @imagettfbbox(12, 0, '/no/such/font.ttf', 'Hi');
echo false === $bad ? "badfont=0\n" : "badfont=1\n";

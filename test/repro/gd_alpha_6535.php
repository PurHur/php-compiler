<?php
declare(strict_types=1);

foreach (['imagealphablending', 'imagesavealpha', 'imagecolorallocatealpha'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$im = imagecreatetruecolor(4, 4);
echo 'blend=', (int) imagealphablending($im, false), "\n";
echo 'save=', (int) imagesavealpha($im, true), "\n";
$trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefilledrectangle($im, 0, 0, 3, 3, $trans);
$pixel = imagecolorat($im, 1, 1);
echo 'alpha=', ($pixel >> 24) & 0x7F, "\n";

ob_start();
imagepng($im);
$png = ob_get_clean();
$pngCtByte = substr($png, 25, 1);
$colorType = ord($pngCtByte);
echo 'png_ct=', $colorType, "\n";

enum E: int { case A = 1; }
try {
    imagealphablending($im, E::A);
    echo "enum_ok\n";
} catch (TypeError $e) {
    echo "enum_te\n";
}

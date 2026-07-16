--TEST--
stdlib imagealphablending/imagesavealpha/imagecolorallocatealpha PNG alpha (#6535, ext/gd/gd.c)
--FILE--
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
// IHDR color type at offset 25 (split call — nested ord(substr()) hits VM arg-boxing quirk)
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
?>
--EXPECT--
imagealphablending=1
imagesavealpha=1
imagecolorallocatealpha=1
blend=1
save=1
alpha=127
png_ct=6
enum_te

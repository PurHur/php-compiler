--TEST--
stdlib imagettftext/imagettfbbox FreeType text (#6532, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
if (!is_readable($font)) {
    echo "SKIP no font\n";
    return;
}
if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
    echo "SKIP no freetype\n";
    return;
}

echo 'imagettftext=', (int) function_exists('imagettftext'), "\n";
echo 'imagettfbbox=', (int) function_exists('imagettfbbox'), "\n";

$bbox = imagettfbbox(12.0, 0.0, $font, 'Hi');
echo is_array($bbox) && 8 === count($bbox) ? "bbox_ok\n" : "bbox_bad\n";
echo isset($bbox[2], $bbox[0]) && $bbox[2] > $bbox[0] ? "bbox_w\n" : "bbox_w0\n";

$im = imagecreatetruecolor(80, 40);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 79, 39, $white);
$drawn = imagettftext($im, 12.0, 0.0, 5, 25, $black, $font, 'Hi');
echo is_array($drawn) && 8 === count($drawn) ? "draw_ok\n" : "draw_bad\n";

$ink = 0;
for ($y = 10; $y < 30; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if (imagecolorat($im, $x, $y) !== $white) {
            ++$ink;
        }
    }
}
echo $ink > 10 ? "ink_ok\n" : "ink_bad\n";

$bad = imagettfbbox(12.0, 0.0, '/no/such/font.ttf', 'x');
echo false === $bad ? "missing_font_ok\n" : "missing_font_bad\n";

enum FontCase: string { case A = 'x'; }
try {
    imagettfbbox(12.0, 0.0, FontCase::A, 'Hi');
    echo "enum_ok\n";
} catch (TypeError $e) {
    echo "enum_typeerror\n";
}
?>
--EXPECTF--
PHP Warning:  imagettfbbox(): Could not find/open font in %s on line %d
imagettftext=1
imagettfbbox=1
bbox_ok
bbox_w
draw_ok
ink_ok
missing_font_ok
enum_typeerror

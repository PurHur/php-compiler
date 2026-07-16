--TEST--
stdlib imageline/imagefilledrectangle/imagestring/imagechar (#6534, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['imageline', 'imagestring', 'imagechar', 'imagefilledrectangle'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$im = imagecreatetruecolor(8, 8);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 7, 7, $white);
echo 'fill=', imagecolorat($im, 3, 3), "\n";
imageline($im, 0, 0, 7, 7, $black);
echo 'diag=', imagecolorat($im, 3, 3), "\n";
echo 'corner=', imagecolorat($im, 0, 0), "\n";

$im2 = imagecreatetruecolor(40, 20);
imagefilledrectangle($im2, 0, 0, 39, 19, $white);
imagestring($im2, 1, 1, 1, 'Hi', $black);
$ink = 0;
for ($y = 0; $y < 20; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if (imagecolorat($im2, $x, $y) === $black) {
            ++$ink;
        }
    }
}
echo 'string_ink=', $ink > 0 ? '1' : '0', "\n";

$im3 = imagecreatetruecolor(10, 10);
imagefilledrectangle($im3, 0, 0, 9, 9, $white);
imagechar($im3, 1, 1, 1, 'X', $black);
$ink = 0;
for ($y = 0; $y < 10; ++$y) {
    for ($x = 0; $x < 10; ++$x) {
        if (imagecolorat($im3, $x, $y) === $black) {
            ++$ink;
        }
    }
}
echo 'char_ink=', $ink > 0 ? '1' : '0', "\n";
?>
--EXPECT--
imageline=1
imagestring=1
imagechar=1
imagefilledrectangle=1
fill=16777215
diag=0
corner=0
string_ink=1
char_ink=1

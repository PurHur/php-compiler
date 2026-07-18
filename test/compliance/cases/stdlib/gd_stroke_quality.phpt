--TEST--
stdlib imageantialias/imagesetthickness stroke quality (#20406, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['imageantialias', 'imagesetthickness'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$im = imagecreatetruecolor(20, 20);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 19, 19, $white);

echo 'aa=', (int) imageantialias($im, true), "\n";
echo 'th=', (int) imagesetthickness($im, 3), "\n";

// Reset AA so thickness is observable as a solid stroke (libgd AA path ignores thick).
imageantialias($im, false);
imagesetthickness($im, 3);
imageline($im, 0, 10, 19, 10, $black);
$ink = 0;
for ($y = 0; $y < 20; ++$y) {
    for ($x = 0; $x < 20; ++$x) {
        if (imagecolorat($im, $x, $y) === $black) {
            ++$ink;
        }
    }
}
// thick=3 horizontal line spans 3 rows × 20 cols = 60
echo 'thick_ink=', $ink, "\n";

$im2 = imagecreatetruecolor(40, 20);
imagefilledrectangle($im2, 0, 0, 39, 19, $white);
imageantialias($im2, true);
imagesetthickness($im2, 1);
imageline($im2, 0, 5, 39, 14, $black);
$aaInk = 0;
$nonWhite = 0;
for ($y = 0; $y < 20; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        $p = imagecolorat($im2, $x, $y);
        if ($p !== $white) {
            ++$nonWhite;
            if ($p !== $black) {
                ++$aaInk;
            }
        }
    }
}
echo 'aa_has_blend=', $aaInk > 0 ? '1' : '0', "\n";
echo 'aa_drawn=', $nonWhite > 0 ? '1' : '0', "\n";
?>
--EXPECT--
imageantialias=1
imagesetthickness=1
aa=1
th=1
thick_ink=60
aa_has_blend=1
aa_drawn=1

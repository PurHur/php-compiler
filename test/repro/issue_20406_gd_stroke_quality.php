<?php
/**
 * Repro #20406 — imageantialias / imagesetthickness registration + thickness honor.
 */
$im = imagecreatetruecolor(20, 20);
echo function_exists('imageantialias') ? "aa=1\n" : "aa=0\n";
echo function_exists('imagesetthickness') ? "th=1\n" : "th=0\n";
echo 'set_aa=', (int) imageantialias($im, true), "\n";
echo 'set_th=', (int) imagesetthickness($im, 3), "\n";

$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 19, 19, $white);
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
echo 'thick_ink=', $ink, "\n";
echo "ok\n";

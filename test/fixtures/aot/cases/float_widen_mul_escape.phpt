--TEST--
AOT: int-local float assign widens; value-box float mul; mandelbrot-shaped escape (#23471)
--FILE--
<?php
function pixel($rec, $imc) {
    $re = 0;
    $im = 0;
    $re2 = 0;
    $im2 = 0;
    $color = 0;
    $re = $rec;
    $im = $imc;
    $color = 40;
    $re2 = $re * $re;
    $im2 = $im * $im;
    while (((($re2 + $im2) < 1000000) && $color > 0)) {
        $im = $re * $im * 2 + $imc;
        $re = $re2 - $im2 + $rec;
        $re2 = $re * $re;
        $im2 = $im * $im;
        $color = $color - 1;
    }
    return $color == 0 ? '_' : '#';
}
$r = 0.7;
$w = 50;
$s = 0;
$s = 2 * $r / $w;
$a = 2;
$a = $a + 0.5;
$b = $a * $a;
$rec = -1.57;
$imc = -0.336;
$zrec = 0.0;
$zimc = 0.0;
$sx = $s * 1000;
$bx = $b * 4;
echo (int) $sx;
echo ' ';
echo (int) $bx;
echo ' ';
echo pixel($rec, $imc), pixel($zrec, $zimc), "\n";
--EXPECT--
27 25 #_

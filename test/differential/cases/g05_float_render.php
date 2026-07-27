<?php
// #23471: the AOT escape condition was wrong, so every cell rendered '_'.
// Float compare in a while-loop with an int-initialised accumulator turning float.
$re2 = 0; $im2 = 0; $color = 50;
$re = 0.35; $im = 0.28;
while ((($re2 + $im2) < 1000000) && $color > 0) {
    $im = $re * $im * 2 + 0.1;
    $re = $re2 - $im2 + 0.35;
    $re2 = $re * $re;
    $im2 = $im * $im;
    $color = $color - 1;
}
echo $color == 0 ? "_" : "#", "\n";
echo "sum=", (($re2 + $im2) > 1000000) ? "escaped" : "bounded", "\n";

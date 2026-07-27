<?php
// #23973 — AOT: two closures in one unit must compile and invoke (e20_closure).
$f = function ($a, $b) {
    return "$a+$b";
};
$x = 1;
echo $f($x + 1, $x + 2), "\n";
$g = fn ($a, $b) => "$a*$b";
echo $g($x + 3, $x + 4), "\n";

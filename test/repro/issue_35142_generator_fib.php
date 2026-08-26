<?php
function f($n) {
    $a = 0;
    $b = 1;
    for ($i = 0; $i < $n; $i++) {
        yield $a;
        $t = $a + $b;
        $a = $b;
        $b = $t;
    }
}
foreach (f(5) as $v) {
    echo $v;
}

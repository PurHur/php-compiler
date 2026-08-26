<?php
// #35166 — AOT while + yield with literal bound (was module verify fail)
function g() {
    $i = 0;
    while ($i < 3) {
        yield $i;
        $i++;
    }
}
foreach (g() as $v) {
    echo $v;
}

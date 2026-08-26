<?php
// #35142 follow-up — AOT while + yield must match Zend/VM (module verify + output)
function g($n) {
    $i = 0;
    while ($i < $n) {
        yield $i;
        $i++;
    }
}
foreach (g(3) as $v) {
    echo $v;
}

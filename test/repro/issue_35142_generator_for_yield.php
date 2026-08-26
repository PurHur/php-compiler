<?php
// #35142 — AOT for-loop yield must match Zend/VM
function g($n) {
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}
foreach (g(3) as $v) {
    echo $v;
}

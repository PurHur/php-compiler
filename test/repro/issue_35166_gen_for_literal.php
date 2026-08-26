<?php
// #35166 — AOT for-loop yield with literal bound must match Zend/VM
function g() {
    for ($i = 0; $i < 3; $i++) {
        yield $i;
    }
}
foreach (g() as $v) {
    echo $v;
}

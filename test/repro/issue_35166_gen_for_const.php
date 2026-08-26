<?php
// #35166 — AOT for-loop yield with file const bound
const N = 3;
function g() {
    for ($i = 0; $i < N; $i++) {
        yield $i;
    }
}
foreach (g() as $v) {
    echo $v;
}

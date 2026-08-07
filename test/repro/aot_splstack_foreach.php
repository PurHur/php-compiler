<?php
// Repro #28705 — AOT SplStack foreach must walk `__spl_ht` LIFO.
$s = new SplStack();
$s->push(1);
$s->push(2);
foreach ($s as $v) {
    echo $v, ',';
}
echo "\n";

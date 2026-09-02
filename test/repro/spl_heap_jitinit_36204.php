<?php
// SplHeap thin-AOT proxies registered via ext/spl/Module::jitInit (#36204 / #26784).
$h = new SplMaxHeap();
$h->insert(1);
$h->insert(3);
$h->insert(2);
echo $h->extract(), ',', $h->extract(), ',', $h->extract(), "\n";

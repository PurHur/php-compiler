<?php
foreach (['gc_disable', 'gc_enable', 'gc_mem_caches', 'gc_collect_cycles', 'gc_enabled'] as $n) {
    $rf = new ReflectionFunction($n);
    echo $n, ' ', $rf->hasReturnType() ? (string) $rf->getReturnType() : '<none>', "\n";
}

<?php
// Repro #24391: shmop_* Reflection + Zend named args (ext/shmop/shmop.stub.php)
foreach (['shmop_open', 'shmop_read', 'shmop_write'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ': ', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
try {
    shmop_open(key: ftok(__FILE__, 't'), mode: 'c', permissions: 0644, size: 128);
    echo "named=ok\n";
} catch (Throwable $e) {
    echo 'named=', $e->getMessage(), "\n";
}

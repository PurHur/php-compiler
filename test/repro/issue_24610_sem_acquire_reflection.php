<?php
// Repro #24610: sem_acquire Reflection + Zend named args (ext/sysvsem/sysvsem.stub.php)
foreach (['sem_acquire', 'sem_release', 'sem_remove'] as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName().($p->isOptional() ? '?' : '');
    }
    echo $fn, '=', implode(',', $parts), ' req=', $r->getNumberOfRequiredParameters(), "\n";
}
$key = 0x24610;
$sem = @sem_get($key, 1);
if (false === $sem) {
    echo "get-fail\n";
    exit(0);
}
try {
    echo @sem_acquire(semaphore: $sem) ? "named=ok\n" : "named=fail\n";
} catch (Throwable $e) {
    echo 'named=', $e->getMessage(), "\n";
}
@sem_release(semaphore: $sem);
@sem_remove(semaphore: $sem);

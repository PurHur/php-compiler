--TEST--
sysvsem sem_acquire/release/remove Reflection names + named bind (#24610, ext/sysvsem/sysvsem.stub.php)
--SKIPIF--
<?php if (!function_exists('sem_acquire')) { print 'skip sysvsem unavailable'; } ?>
--FILE--
<?php
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
    $ok = @sem_acquire(semaphore: $sem, non_blocking: false);
    echo $ok ? "named-acq-ok\n" : "named-acq-fail\n";
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Unknown named parameter')) {
        echo 'named-acq:', $e->getMessage(), "\n";
    } else {
        echo "named-acq-ok\n";
    }
}
try {
    @sem_release(semaphore: $sem);
    echo "named-rel-ok\n";
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Unknown named parameter')) {
        echo 'named-rel:', $e->getMessage(), "\n";
    } else {
        echo "named-rel-ok\n";
    }
}
try {
    @sem_acquire(id: $sem);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
try {
    @sem_acquire(semaphore: $sem, nowait: true);
    echo "nowait-ok\n";
} catch (Throwable $e) {
    echo 'nowait:', $e->getMessage(), "\n";
}
@sem_remove(semaphore: $sem);
echo "done\n";
?>
--EXPECT--
sem_acquire=semaphore,non_blocking? req=1
sem_release=semaphore req=1
sem_remove=semaphore req=1
named-acq-ok
named-rel-ok
legacy:Unknown named parameter $id
nowait:Unknown named parameter $nowait
done

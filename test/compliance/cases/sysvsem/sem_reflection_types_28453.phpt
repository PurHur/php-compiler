--TEST--
stdlib sem_* Reflection SysvSemaphore stubs (#28453, ext/sysvsem/sysvsem.stub.php)
--SKIPIF--
<?php if (!function_exists('sem_acquire')) { print 'skip sysvsem unavailable'; } ?>
--FILE--
<?php
foreach (['sem_get', 'sem_acquire', 'sem_release', 'sem_remove'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $opt = $p->isOptional() ? '=?' : '';
        $ps[] = $t . '$' . $p->getName() . $opt;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
echo 'class=', class_exists('SysvSemaphore') ? 'Y' : 'N', "\n";
?>
--EXPECT--
sem_get(int $key, int $max_acquire=?, int $permissions=?, bool $auto_release=?): SysvSemaphore|false
sem_acquire(SysvSemaphore $semaphore, bool $non_blocking=?): bool
sem_release(SysvSemaphore $semaphore): bool
sem_remove(SysvSemaphore $semaphore): bool
class=Y

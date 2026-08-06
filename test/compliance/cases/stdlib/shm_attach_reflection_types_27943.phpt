--TEST--
stdlib shm_* Reflection SysvSharedMemory stubs (#27943, ext/sysvshm/sysvshm.stub.php)
--SKIPIF--
<?php if (!function_exists('shm_attach')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
foreach (['shm_attach', 'shm_detach', 'shm_put_var', 'shm_get_var', 'shm_has_var', 'shm_remove', 'shm_remove_var'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $bit = $t . '$' . $p->getName();
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $bit .= '=' . var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional()) {
            $bit .= '=?';
        }
        $ps[] = $bit;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
echo 'class=', class_exists('SysvSharedMemory') ? 'Y' : 'N', "\n";
?>
--EXPECT--
shm_attach(int $key, ?int $size=NULL, int $permissions=438): SysvSharedMemory|false
shm_detach(SysvSharedMemory $shm): bool
shm_put_var(SysvSharedMemory $shm, int $key, mixed $value): bool
shm_get_var(SysvSharedMemory $shm, int $key): mixed
shm_has_var(SysvSharedMemory $shm, int $key): bool
shm_remove(SysvSharedMemory $shm): bool
shm_remove_var(SysvSharedMemory $shm, int $key): bool
class=Y

--TEST--
stdlib shmop_* Reflection Shmop stubs (#28451, ext/shmop/shmop.stub.php)
--SKIPIF--
<?php if (!function_exists('shmop_open')) { print 'skip shmop unavailable'; } ?>
--FILE--
<?php
foreach (['shmop_open', 'shmop_read', 'shmop_write', 'shmop_size', 'shmop_delete', 'shmop_close'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ps[] = $t . '$' . $p->getName();
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
echo 'class=', class_exists('Shmop') ? 'Y' : 'N', "\n";
?>
--EXPECT--
shmop_open(int $key, string $mode, int $permissions, int $size): Shmop|false
shmop_read(Shmop $shmop, int $offset, int $size): string
shmop_write(Shmop $shmop, string $data, int $offset): int
shmop_size(Shmop $shmop): int
shmop_delete(Shmop $shmop): bool
shmop_close(Shmop $shmop): void
class=Y

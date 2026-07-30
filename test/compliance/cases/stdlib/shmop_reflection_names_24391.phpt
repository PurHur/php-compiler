--TEST--
stdlib shmop_open/read/write Reflection names + named open (#24391, ext/shmop/shmop.stub.php)
--FILE--
<?php
foreach (['shmop_open', 'shmop_read', 'shmop_write', 'shmop_size', 'shmop_close', 'shmop_delete'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
$key = ftok(__FILE__, 't');
try {
    $shm = @shmop_open(key: $key, mode: 'c', permissions: 0644, size: 128);
    echo "named-open-ok\n";
    if (false !== $shm && null !== $shm) {
        @shmop_close(shmop: $shm);
        @shmop_delete(shmop: $shm);
    }
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Unknown named parameter')) {
        echo 'named-open:', $e->getMessage(), "\n";
    } else {
        // Named args bound; SysV attach/availability errors are out of scope for this guard.
        echo "named-open-ok\n";
    }
}
try {
    @shmop_open(key: $key, flags: 'c', mode: 0644, size: 128);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
?>
--EXPECT--
shmop_open=key,mode,permissions,size
shmop_read=shmop,offset,size
shmop_write=shmop,data,offset
shmop_size=shmop
shmop_close=shmop
shmop_delete=shmop
named-open-ok
legacy:Unknown named parameter $flags

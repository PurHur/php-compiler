--TEST--
stdlib shm_* Reflection names + named attach/put (#24640, ext/sysvshm/sysvshm.stub.php)
--SKIPIF--
<?php if (!function_exists('shm_attach')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
foreach (['shm_attach', 'shm_detach', 'shm_put_var', 'shm_get_var', 'shm_has_var', 'shm_remove', 'shm_remove_var'] as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName().($p->isOptional() ? '?' : '');
    }
    echo $fn, '=', implode(',', $parts), ' req=', $r->getNumberOfRequiredParameters(), "\n";
}

$key = 0x24640;
try {
    $shm = @shm_attach(key: $key, size: 1024, permissions: 0666);
    if (false === $shm) {
        echo "named-attach-fail\n";
    } else {
        echo "named-attach-ok\n";
        @shm_put_var(shm: $shm, key: 1, value: 42);
        echo @shm_get_var(shm: $shm, key: 1), "\n";
        @shm_remove(shm: $shm);
    }
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Unknown named parameter')) {
        echo 'named-attach:', $e->getMessage(), "\n";
    } else {
        echo "named-attach-ok\n";
    }
}
try {
    @shm_attach(key: $key, memsize: 1024);
    echo "legacy-memsize-ok\n";
} catch (Throwable $e) {
    echo 'legacy-memsize:', $e->getMessage(), "\n";
}
try {
    @shm_attach(key: $key, size: 1024, perm: 0666);
    echo "legacy-perm-ok\n";
} catch (Throwable $e) {
    echo 'legacy-perm:', $e->getMessage(), "\n";
}
try {
    @shm_put_var(shm_identifier: null, variable_key: 1, variable: 1);
    echo "legacy-put-ok\n";
} catch (Throwable $e) {
    echo 'legacy-put:', $e->getMessage(), "\n";
}
echo "done\n";
?>
--EXPECT--
shm_attach=key,size?,permissions? req=1
shm_detach=shm req=1
shm_put_var=shm,key,value req=3
shm_get_var=shm,key req=2
shm_has_var=shm,key req=2
shm_remove=shm req=1
shm_remove_var=shm,key req=2
named-attach-ok
42
legacy-memsize:Unknown named parameter $memsize
legacy-perm:Unknown named parameter $perm
legacy-put:Unknown named parameter $shm_identifier
done

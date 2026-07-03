--TEST--
JIT: rmdir() via RmdirJitHelper PHP (#15481)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rmdir_fixture';
$dir = $base . '/jit_one';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (mkdir($dir, 0755) && rmdir($dir)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
--EXPECT--
ok

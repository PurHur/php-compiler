--TEST--
JIT: chmod() via libc chmod(2)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/chmod_fixture/jit_data.txt';
if (file_put_contents($path, 'x') && chmod($path, 0600)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
--EXPECT--
ok

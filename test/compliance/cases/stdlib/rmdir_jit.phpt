--TEST--
JIT: rmdir() via libc rmdir(2)
--FILE--
<?php
$one = 'test/compliance/cases/stdlib/rmdir_fixture/jit_one';
if (mkdir($one, 0700) && rmdir($one)) {
    if (is_dir($one)) {
        echo "bad\n";
    } else {
        echo "ok\n";
    }
} else {
    echo "fail\n";
}
--EXPECT--
ok

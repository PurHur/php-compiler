--TEST--
AOT: mkdir() creates directory via libc mkdir(2)
--FILE--
<?php
$one = 'test/compliance/cases/stdlib/mkdir_fixture/aot_one';
if (mkdir($one, 0700)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
--EXPECT--
ok

--TEST--
AOT: mkdir() creates directory via libc mkdir(2)
--FILE--
<?php
$one = 'test/compliance/cases/stdlib/mkdir_fixture/aot_one';
$nested = 'test/compliance/cases/stdlib/mkdir_fixture/aot_nested/deep';
if (is_dir($nested)) {
    rmdir($nested);
}
$nestedParent = dirname($nested);
if (is_dir($nestedParent)) {
    rmdir($nestedParent);
}
if (is_dir($one)) {
    rmdir($one);
}
if (mkdir($one, 0700)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
if (mkdir($nested, 0777, true)) {
    if (is_dir($nested)) {
        echo "rec\n";
    } else {
        echo "badrec\n";
    }
} else {
    echo "failrec\n";
}
--EXPECT--
ok
rec

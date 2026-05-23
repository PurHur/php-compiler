--TEST--
JIT: mkdir() via libc mkdir(2)
--FILE--
<?php
$one = 'test/compliance/cases/stdlib/mkdir_fixture/jit_one';
if (mkdir($one, 0700)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
$default = 'test/compliance/cases/stdlib/mkdir_fixture/jit_default';
if (mkdir($default)) {
    echo is_dir($default) ? "def\n" : "baddef\n";
} else {
    echo "faildef\n";
}
$nested = 'test/compliance/cases/stdlib/mkdir_fixture/jit_nested/deep';
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
def
rec

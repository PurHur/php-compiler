--TEST--
JIT: mkdir() via libc mkdir(2)
--FILE--
<?php
$default = 'test/compliance/cases/stdlib/mkdir_fixture/jit_default';
if (mkdir($default)) {
    if (is_dir($default)) {
        echo "def\n";
    } else {
        echo "baddef\n";
    }
} else {
    echo "faildef\n";
}
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
def
ok
rec

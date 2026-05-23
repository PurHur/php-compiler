--TEST--
stdlib mkdir()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/mkdir_fixture';
$one = $base . '/one';
if (mkdir($one, 0755)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
$nested = $base . '/nested/deep';
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

--TEST--
AOT: unlink() removes file via libc unlink(2)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/unlink_fixture';
$path = $base . '/tmp.txt';
$n = file_put_contents($path, 'x');
if (unlink($path)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (unlink($path)) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone

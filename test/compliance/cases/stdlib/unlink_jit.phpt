--TEST--
JIT: unlink() via libc unlink(2)
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
if (unlink('/no/such/phpc-unlink-path')) {
    echo 'badgone', "\n";
} else {
    echo 'nogone', "\n";
}
--EXPECT--
ok
gone
nogone

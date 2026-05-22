--TEST--
JIT: is_readable() via access R_OK
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/readfile_fixture';
$path = $base . '/data.txt';
if (is_readable($path)) {
    echo 'readable', "\n";
} else {
    echo 'denied', "\n";
}
if (is_readable($base)) {
    echo 'dir', "\n";
} else {
    echo 'nodir', "\n";
}
if (is_readable('/no/such/phpc-readable-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
readable
dir
gone

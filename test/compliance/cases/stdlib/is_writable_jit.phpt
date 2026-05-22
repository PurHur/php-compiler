--TEST--
JIT: is_writable() via access W_OK
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/readfile_fixture';
$path = $base . '/data.txt';
if (is_writable($path)) {
    echo 'writable', "\n";
} else {
    echo 'denied', "\n";
}
if (is_writable($base)) {
    echo 'dir', "\n";
} else {
    echo 'nodir', "\n";
}
if (is_writable('/no/such/phpc-writable-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
writable
dir
gone

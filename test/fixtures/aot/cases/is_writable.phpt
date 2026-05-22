--TEST--
AOT: is_writable() via access W_OK
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
if (is_writable($path)) {
    echo 'yes', "\n";
} else {
    echo 'no', "\n";
}
if (is_writable('/no/such/phpc-writable-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
yes
gone

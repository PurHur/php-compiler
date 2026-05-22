--TEST--
AOT: is_readable() via access R_OK
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
if (is_readable($path)) {
    echo 'yes', "\n";
} else {
    echo 'no', "\n";
}
if (is_readable('/no/such/phpc-readable-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
yes
gone

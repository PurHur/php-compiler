--TEST--
AOT: is_executable() via access X_OK
--FILE--
<?php
$bin = '/bin/sh';
if (is_executable($bin)) {
    echo 'bin', "\n";
} else {
    echo 'nobin', "\n";
}
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
if (is_executable($path)) {
    echo 'bad', "\n";
} else {
    echo 'notexec', "\n";
}
if (is_executable('/no/such/phpc-executable-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
bin
notexec
gone

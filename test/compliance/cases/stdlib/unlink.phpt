--TEST--
stdlib unlink()
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/unlink_fixture.txt';
file_put_contents($path, 'x');
if (unlink($path)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (file_exists($path)) {
    echo 'still', "\n";
} else {
    echo 'gone', "\n";
}
$missing = '/no/such/phpc-unlink-path';
if (unlink($missing)) {
    echo 'bad', "\n";
} else {
    echo 'no', "\n";
}
--EXPECT--
ok
gone
no

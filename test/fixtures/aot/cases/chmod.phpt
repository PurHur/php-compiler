--TEST--
AOT: chmod() sets mode via libc chmod(2)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/chmod_fixture/aot_data.txt';
if (file_put_contents($path, 'x') && chmod($path, 0640)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
--EXPECT--
ok

--TEST--
stdlib filemtime()
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$t1 = filemtime($path);
$t2 = filemtime($path);
if ($t1 === false || $t2 === false || $t1 !== $t2) {
    echo 'fail', "\n";
} else {
    echo 'ok', "\n";
}
--EXPECT--
ok

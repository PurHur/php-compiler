--TEST--
AOT: filemtime() via stat st_mtime
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
if (filemtime('/no/such/phpc-filemtime-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone

--TEST--
JIT: filesize() via stat st_size
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$size = filesize($path);
if ($size === false) {
    echo 'fail', "\n";
} else {
    echo $size, "\n";
}
if (filesize('/no/such/phpc-filesize-path') === false) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
--EXPECT--
15
gone

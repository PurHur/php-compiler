--TEST--
AOT: filesize() via stat st_size
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$size = filesize($path);
if ($size === false) {
    echo 'fail', "\n";
} else {
    echo $size, "\n";
}
--EXPECT--
15

--TEST--
AOT readfile() return value is bytes read from file (issue #4932)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_return_bytes_fixture/two.bin';
$n = readfile($path);
echo $n, "\n";
--EXPECT--
ab2

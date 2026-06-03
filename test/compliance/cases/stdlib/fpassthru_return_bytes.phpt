--TEST--
stdlib fpassthru() return value is bytes read from stream (issue #4932, ext/standard/streams.c)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fpassthru_return_bytes_fixture/two.bin';
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
echo $n, "\n";
--EXPECT--
ab2

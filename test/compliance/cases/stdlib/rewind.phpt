--TEST--
stdlib rewind() after fseek on file handle (issue #3579)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
fseek($fp, 5);
rewind($fp);
echo fgets($fp, 4);
echo rewind(-999) ? '1' : '0';
--EXPECT--
lin0

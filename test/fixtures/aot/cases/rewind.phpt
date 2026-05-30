--TEST--
AOT: rewind() after fseek on file handle (issue #3579)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
fseek($fp, 5);
echo rewind($fp) ? '1' : '0';
echo fgets($fp, 4);
fclose($fp);
--EXPECT--
1lin

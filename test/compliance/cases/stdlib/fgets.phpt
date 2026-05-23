--TEST--
stdlib fgets() reads lines from a file handle (issue #1187)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
$line1 = fgets($fp);
echo $line1;
$line2 = fgets($fp, 6);
echo $line2, "\n";
$line3 = fgets($fp);
echo $line3;
$line4 = fgets($fp);
echo $line4;
$eof = fgets($fp);
echo $eof === false ? 'eof' : 'more', "\n";
fclose($fp);
--EXPECT--
line one
line 
two
line three
eof

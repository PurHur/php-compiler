--TEST--
Negative string offsets read and write (PHP 7.1+, issue #3751)
--FILE--
<?php
echo "abc"[-1], "\n";
echo "abc"[-2], "\n";
$s = 'abc';
$s[-1] = 'z';
echo $s, "\n";
echo var_export('abc'[-1], true), "\n";
--EXPECT--
c
b
abz
'c'

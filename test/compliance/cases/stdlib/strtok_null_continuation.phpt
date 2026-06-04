--TEST--
stdlib strtok() null $string continues tokenization (#5515)
--FILE--
<?php
$s = 'a,b,c';
echo strtok($s, ','), '|';
echo strtok(null, ','), "\n";
--EXPECT--
a|b

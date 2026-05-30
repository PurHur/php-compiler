--TEST--
language ++ on alphanumeric strings (issue #3469)
--FILE--
<?php
$a = '9';
$a++;
echo $a, "\n";

$b = 'a9';
$b++;
echo $b, "\n";

$c = 'AA9';
$c++;
echo $c, "\n";

$d = 'Az';
$d++;
echo $d, "\n";

$e = 'a9';
$e--;
echo $e, "\n";

$f = '10';
$f--;
echo $f, "\n";
--EXPECT--
10
b0
AB0
Ba
a9
9

--TEST--
language ++ on alphanumeric strings (issue #3469, #21911)
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

// Carry / case / digit-overflow matrix (Zend increment_string, #21911)
foreach (['Z', '9z', 'z', 'A9', 'ZZ', 'zz', '999'] as $s0) {
    $s = $s0;
    $s++;
    echo "$s0 => $s\n";
}
--EXPECT--
10
b0
AB0
Ba
a9
9
Z => AA
9z => 10a
z => aa
A9 => B0
ZZ => AAA
zz => aaa
999 => 1000

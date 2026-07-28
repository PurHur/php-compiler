--TEST--
AOT: by-reference int parameter updates caller (#24162, Zend ZEND_SEND_REF)
--FILE--
<?php
function assignNine(int &$x): void {
    $x = 9;
}
function increment(int &$x): void {
    $x++;
}
function outer(): void {
    $n = 1;
    assignNine($n);
    echo 'O:', $n, "\n";
}
$a = 1;
assignNine($a);
echo $a, "\n";
$b = 5;
increment($b);
echo $b, "\n";
outer();
--EXPECT--
9
6
O:9

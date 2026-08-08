--TEST--
stdlib usort/uasort/uksort bool comparator — Zend Deprecated once (#29089, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(function (int $no, string $str) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $str;
    }
    return true;
});

$a = [3, 1, 2];
usort($a, static fn ($x, $y) => $x > $y);
echo implode(',', $a), "\n";

$b = ['c' => 3, 'a' => 1, 'b' => 2];
uasort($b, static fn ($x, $y) => $x > $y);
echo implode(',', $b), "\n";

$c = ['c' => 3, 'a' => 1, 'b' => 2];
uksort($c, static fn ($x, $y) => strcmp($x, $y) > 0);
echo implode(',', array_keys($c)), "\n";

echo implode("\n", $deps), "\n";
echo 'count=', count($deps), "\n";
--EXPECT--
1,2,3
1,2,3
a,b,c
usort(): Returning bool from comparison function is deprecated, return an integer less than, equal to, or greater than zero
uasort(): Returning bool from comparison function is deprecated, return an integer less than, equal to, or greater than zero
uksort(): Returning bool from comparison function is deprecated, return an integer less than, equal to, or greater than zero
count=3
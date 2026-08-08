--TEST--
stdlib usort/uasort/uksort object comparator — Zend Warning + coerce (#29124, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
$warnings = [];
set_error_handler(function (int $no, string $str) use (&$warnings): bool {
    if ($no === E_WARNING) {
        $warnings[] = $str;
    }
    return true;
});

$a = [3, 1, 2];
usort($a, static fn ($x, $y) => new stdClass());
echo implode(',', $a), "\n";

$b = ['c' => 3, 'a' => 1, 'b' => 2];
uasort($b, static fn ($x, $y) => new stdClass());
echo implode(',', $b), ' keys=', implode(',', array_keys($b)), "\n";

$c = ['c' => 3, 'a' => 1, 'b' => 2];
uksort($c, static fn ($x, $y) => new stdClass());
echo implode(',', $c), ' keys=', implode(',', array_keys($c)), "\n";

echo implode("\n", $warnings), "\n";
echo 'count=', count($warnings), "\n";
--EXPECT--
1,2,3
1,2,3 keys=a,b,c
1,2,3 keys=a,b,c
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
count=9

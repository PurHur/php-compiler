--TEST--
language: float bitwise ops warn only on precision loss and still return ints (#23755, zend_operators.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$seen = [];
echo 'and_exact=', 5.0 & 3, "\n";
echo 'and_exact_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
echo 'and_lossy=', 5.7 & 3, "\n";
echo 'and_lossy_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'and_lossy_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'or_exact=', 5.0 | 3, "\n";
echo 'or_exact_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
echo 'xor_exact=', 5.0 ^ 3, "\n";
echo 'xor_exact_depr=', empty($seen) ? '0' : '1', "\n";
--EXPECT--
and_exact=1
and_exact_depr=0
and_lossy=1
and_lossy_depr=1
and_lossy_msg=Implicit conversion from float 5.7 to int loses precision
or_exact=7
or_exact_depr=0
xor_exact=6
xor_exact_depr=0

--TEST--
language: float→int typed param emits E_DEPRECATED on precision loss (#23533, zend_operators.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

function f(int $x): int
{
    return $x;
}

$seen = [];
echo 'lossy=', f(1.5), "\n";
echo 'lossy_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'lossy_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'integral=', f(1.0), "\n";
echo 'integral_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
echo 'neg=', f(-1.7), "\n";
echo 'neg_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
--EXPECT--
lossy=1
lossy_depr=1
lossy_msg=Implicit conversion from float 1.5 to int loses precision
integral=1
integral_depr=0
neg=-1
neg_depr=1

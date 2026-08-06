--TEST--
language: INF/NAN→int untyped coerce emits E_DEPRECATED (#27926, zend_operators.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$seen = [];
echo 'bit=', INF | 1, "\n";
echo 'bit_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'bit_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
$a = [1, 2, 3];
echo 'dim=', $a[INF] ?? 'miss', "\n";
echo 'dim_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'dim_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'neg=', ((-INF) | 1), "\n";
echo 'neg_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'nan=', (NAN | 1), "\n";
echo 'nan_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'finite=', (1.5 | 2), "\n";
echo 'finite_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
$b = [1, 2, 3];
$_ = $b[1.5] ?? 'miss';
echo 'dim_finite_read_depr=', empty($seen) ? '0' : '1', "\n";
--EXPECT--
bit=1
bit_depr=1
bit_msg=Implicit conversion from float INF to int loses precision
dim=1
dim_depr=1
dim_msg=Implicit conversion from float INF to int loses precision
neg=1
neg_msg=Implicit conversion from float -INF to int loses precision
nan=1
nan_msg=Implicit conversion from float NAN to int loses precision
finite=3
finite_msg=Implicit conversion from float 1.5 to int loses precision
dim_finite_read_depr=0

--TEST--
Language: NaN/INF relational comparisons — Zend zend_operators.c parity (#4712)
--FILE--
<?php
$nan = acos(2);
$inf = 1e300 * 1e300;

echo ($nan < $nan) ? 'lt' : 'nlt', ' ';
echo ($nan > 1) ? 'gt' : 'ngt', ' ';
echo ($nan <= $nan) ? 'le' : 'nle', ' ';
echo ($nan == $nan) ? 'eq' : 'neq', ' ';
echo ($nan <=> $nan), ' ';
echo ($inf > 1e308) ? 'inf_gt' : 'inf_fail', "\n";

$a = [NAN, 1.0, 2.0];
usort($a, fn ($x, $y) => $x <=> $y);
echo $a[0], ' ', $a[1], ' ', (is_nan($a[2]) ? 'nan' : 'no'), "\n";
--EXPECT--
nlt ngt nle neq 1 inf_gt
1 2 nan

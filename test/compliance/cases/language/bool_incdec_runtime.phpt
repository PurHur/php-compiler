--TEST--
bool increment/decrement runtime: bool inc/dec is no-op (issue #7058)
--FILE--
<?php
declare(strict_types=1);

$cases = [
    ['pre',  function () { $t = true;  ++$t; return $t; }],
    ['post', function () { $t = true;  $t++; return $t; }],
    ['false_pre', function () { $f = false; ++$f; return $f; }],
];

foreach ($cases as [$label, $fn]) {
    $v = $fn();
    echo "$label=" . get_debug_type($v) . " " . var_export($v, true) . "\n";
}

$t = true;
$ret = $t++;
echo "post_ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$t = true;
$ret = $t--;
echo "post_dec_ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$f = false;
$ret = ++$f;
echo "false_pre_ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";

$f = false;
$ret = --$f;
echo "false_pre_dec=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";
--EXPECT--
pre=bool true
post=bool true
false_pre=bool false
post_ret=bool true after=bool true
post_dec_ret=bool true after=bool true
false_pre_ret=bool false after=bool false
false_pre_dec=bool false after=bool false

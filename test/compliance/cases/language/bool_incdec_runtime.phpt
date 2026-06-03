--TEST--
bool increment/decrement runtime: bool promoted to int (issue #4727)
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
    echo "$label=$v type=" . gettype($v) . "\n";
}

$t = true;
$ret = $t++;
echo "post_ret=" . ($ret ? 'true' : 'false') . ' type=' . gettype($ret) . " after=$t\n";

$t = true;
$ret = $t--;
echo "post_dec_ret=" . ($ret ? 'true' : 'false') . ' type=' . gettype($ret) . " after=$t\n";

$f = false;
$ret = ++$f;
echo "false_pre_ret=$ret after=$f\n";

$f = false;
$ret = --$f;
echo "false_pre_dec=$ret after=$f\n";
--EXPECT--
pre=1 type=integer
post=1 type=integer
false_pre=1 type=integer
post_ret=true type=boolean after=1
post_dec_ret=true type=boolean after=0
false_pre_ret=1 after=1
false_pre_dec=-1 after=-1

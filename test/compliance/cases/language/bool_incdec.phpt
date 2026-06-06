--TEST--
bool increment/decrement: ++/-- on true/false (issue #4348, #7058)
--FILE--
<?php
$t = true;
$ret = $t++;
echo "post++ true ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$f = false;
$ret = $f++;
echo "post++ false ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";

$t = true;
$ret = ++$t;
echo "pre++ true ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$f = false;
$ret = ++$f;
echo "pre++ false ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";

$t = true;
$ret = $t--;
echo "post-- true ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$f = false;
$ret = $f--;
echo "post-- false ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";

$t = true;
$ret = --$t;
echo "pre-- true ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($t) . " " . var_export($t, true) . "\n";

$f = false;
$ret = --$f;
echo "pre-- false ret=" . get_debug_type($ret) . " " . var_export($ret, true)
    . " after=" . get_debug_type($f) . " " . var_export($f, true) . "\n";
--EXPECT--
post++ true ret=bool true after=bool true
post++ false ret=bool false after=bool false
pre++ true ret=bool true after=bool true
pre++ false ret=bool false after=bool false
post-- true ret=bool true after=bool true
post-- false ret=bool false after=bool false
pre-- true ret=bool true after=bool true
pre-- false ret=bool false after=bool false

--TEST--
bool increment/decrement: ++/-- on true/false (issue #4348)
--FILE--
<?php
$t = true;
$ret = $t++;
echo "post++ true ret=" . var_export($ret, true) . " after=" . var_export($t, true) . "\n";

$f = false;
$ret = $f++;
echo "post++ false ret=" . var_export($ret, true) . " after=" . var_export($f, true) . "\n";

$t = true;
$ret = ++$t;
echo "pre++ true ret=" . var_export($ret, true) . " after=" . var_export($t, true) . "\n";

$f = false;
$ret = ++$f;
echo "pre++ false ret=" . var_export($ret, true) . " after=" . var_export($f, true) . "\n";

$t = true;
$ret = $t--;
echo "post-- true ret=" . var_export($ret, true) . " after=" . var_export($t, true) . "\n";

$f = false;
$ret = $f--;
echo "post-- false ret=" . var_export($ret, true) . " after=" . var_export($f, true) . "\n";

$t = true;
$ret = --$t;
echo "pre-- true ret=" . var_export($ret, true) . " after=" . var_export($t, true) . "\n";

$f = false;
$ret = --$f;
echo "pre-- false ret=" . var_export($ret, true) . " after=" . var_export($f, true) . "\n";
--EXPECT--
post++ true ret=true after=true
post++ false ret=false after=false
pre++ true ret=true after=true
pre++ false ret=false after=false
post-- true ret=true after=true
post-- false ret=false after=false
pre-- true ret=true after=true
pre-- false ret=false after=false


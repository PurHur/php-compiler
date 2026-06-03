--TEST--
bool increment/decrement: ++/-- on true/false (issue #4348, #4727)
--FILE--
<?php
$t = true;
$ret = $t++;
echo "post++ true ret=" . ($ret ? 'true' : 'false') . " after=$t\n";

$f = false;
$ret = $f++;
echo "post++ false ret=" . ($ret ? 'true' : 'false') . " after=$f\n";

$t = true;
$ret = ++$t;
echo "pre++ true ret=$ret after=$t\n";

$f = false;
$ret = ++$f;
echo "pre++ false ret=$ret after=$f\n";

$t = true;
$ret = $t--;
echo "post-- true ret=" . ($ret ? 'true' : 'false') . " after=$t\n";

$f = false;
$ret = $f--;
echo "post-- false ret=" . ($ret ? 'true' : 'false') . " after=$f\n";

$t = true;
$ret = --$t;
echo "pre-- true ret=$ret after=$t\n";

$f = false;
$ret = --$f;
echo "pre-- false ret=$ret after=$f\n";
--EXPECT--
post++ true ret=true after=1
post++ false ret=false after=1
pre++ true ret=1 after=1
pre++ false ret=1 after=1
post-- true ret=true after=0
post-- false ret=false after=-1
pre-- true ret=0 after=0
pre-- false ret=-1 after=-1

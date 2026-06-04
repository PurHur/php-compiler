<?php
// Issue #5353 — string offset write extends/pads like Zend.
$s = 'a';
$s[1] = 'b';
var_export($s);
echo "\n";

$s = 'ab';
$s[5] = 'x';
var_export($s);
echo "\n";

$s = 'ab';
$s[-5] = 'z';
var_export($s);
echo "\n";

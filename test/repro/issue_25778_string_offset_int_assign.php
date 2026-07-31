<?php
// Issue #25778 — int RHS string offset assign: Zend stringify then first byte
error_reporting(E_ALL);
$s = 'abc';
$s[1] = 65;
var_export($s);
echo "\n";
$t = 'abc';
$t[1] = 0;
var_export($t);
echo "\n";

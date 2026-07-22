<?php
// Issue #22380 — multi-byte RHS to string offset must E_WARNING (Zend/zend_execute.c).
$s = 'abc';
$s[1] = 'XYZ';
echo $s, PHP_EOL;

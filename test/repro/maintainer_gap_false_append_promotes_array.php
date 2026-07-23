<?php
/**
 * $false[] = $v promotes false→array like null (php-src Zend/zend_execute.c).
 * Expected Zend: array(0=>1) + type array
 */
$f = false;
$f[] = 1;
var_export($f);
echo "\n";
echo gettype($f), "\n";

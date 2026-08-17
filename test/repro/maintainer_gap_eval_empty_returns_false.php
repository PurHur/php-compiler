<?php
/**
 * Maintainer gap: eval('') returns false (zend_eval_stringl FAILURE), not NULL (#31914).
 * Controls: eval(';') and whitespace-only remain NULL (successful empty statement list).
 */
error_reporting(E_ALL);

var_export(eval(''));
echo "\n";
var_export(eval(';'));
echo "\n";
var_export(eval('   '));
echo "\n";
$empty = '';
var_export(eval($empty));
echo "\n";

<?php

/**
 * AOT class_alias() must register the alias like VM/JIT (#27010).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 */
class C {}
var_export(class_alias('C', 'D'));
echo "\n";
var_export(class_exists('D'));
echo "\n";

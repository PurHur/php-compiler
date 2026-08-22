<?php
// #32285: mod_function returns 0 for n % -1 without converting n (Zend/zend_operators.c).
var_dump(PHP_INT_MIN % -1);
var_dump((-PHP_INT_MIN) % -1);

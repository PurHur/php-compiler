--TEST--
stdlib: by-ref Error wording is "cannot be passed by reference" on PROFILE=8.2 (#30230, zend_execute.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
error_reporting(0);
$fn = 'array_shift';
try { $fn([1, 2]); } catch (Error $e) { echo 'array_shift: ', $e->getMessage(), "\n"; }
$fn = 'array_pop';
try { $fn([1, 2]); } catch (Error $e) { echo 'array_pop: ', $e->getMessage(), "\n"; }
$fn = 'sort';
try { $fn(null); } catch (Error $e) { echo 'sort: ', $e->getMessage(), "\n"; }
--EXPECT--
array_shift: array_shift(): Argument #1 ($array) cannot be passed by reference
array_pop: array_pop(): Argument #1 ($array) cannot be passed by reference
sort: sort(): Argument #1 ($array) cannot be passed by reference

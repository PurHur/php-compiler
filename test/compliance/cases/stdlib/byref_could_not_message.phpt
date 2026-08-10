--TEST--
stdlib: by-ref Error wording is "could not be passed by reference" (#29624, zend_execute.c)
--FILE--
<?php
error_reporting(0);
$fn = 'sort';
try { $fn(null); } catch (Error $e) { echo 'sort: ', $e->getMessage(), "\n"; }
$fn = 'array_push';
try { $fn(null, 1); } catch (Error $e) { echo 'array_push: ', $e->getMessage(), "\n"; }
$fn = 'shuffle';
try { $fn(null); } catch (Error $e) { echo 'shuffle: ', $e->getMessage(), "\n"; }
$fn = 'array_pop';
try { $fn(null); } catch (Error $e) { echo 'array_pop: ', $e->getMessage(), "\n"; }
$fn = 'array_shift';
try { $fn(null); } catch (Error $e) { echo 'array_shift: ', $e->getMessage(), "\n"; }
$fn = 'array_unshift';
try { $fn(null, 1); } catch (Error $e) { echo 'array_unshift: ', $e->getMessage(), "\n"; }
$fn = 'array_push';
try { $fn([1], 2); } catch (Error $e) { echo 'array_push_literal: ', $e->getMessage(), "\n"; }
--EXPECT--
sort: sort(): Argument #1 ($array) could not be passed by reference
array_push: array_push(): Argument #1 ($array) could not be passed by reference
shuffle: shuffle(): Argument #1 ($array) could not be passed by reference
array_pop: array_pop(): Argument #1 ($array) could not be passed by reference
array_shift: array_shift(): Argument #1 ($array) could not be passed by reference
array_unshift: array_unshift(): Argument #1 ($array) could not be passed by reference
array_push_literal: array_push(): Argument #1 ($array) could not be passed by reference

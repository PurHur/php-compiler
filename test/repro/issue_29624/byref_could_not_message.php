<?php
// #29624: Zend 8.4 by-ref Error wording is "could not be passed by reference"
// Variable callables so null/literal args reach runtime (direct call is compile-rejected).
error_reporting(E_ALL);

$fn = 'sort';
try { $fn(null); } catch (Throwable $t) { echo 'sort: ', $t->getMessage(), "\n"; }

$fn = 'array_push';
try { $fn(null, 1); } catch (Throwable $t) { echo 'array_push: ', $t->getMessage(), "\n"; }

$fn = 'shuffle';
try { $fn(null); } catch (Throwable $t) { echo 'shuffle: ', $t->getMessage(), "\n"; }

$fn = 'array_pop';
try { $fn(null); } catch (Throwable $t) { echo 'array_pop: ', $t->getMessage(), "\n"; }

$fn = 'array_shift';
try { $fn(null); } catch (Throwable $t) { echo 'array_shift: ', $t->getMessage(), "\n"; }

$fn = 'array_unshift';
try { $fn(null, 1); } catch (Throwable $t) { echo 'array_unshift: ', $t->getMessage(), "\n"; }

$fn = 'array_push';
try { $fn([1], 2); } catch (Throwable $t) { echo 'array_push_literal: ', $t->getMessage(), "\n"; }

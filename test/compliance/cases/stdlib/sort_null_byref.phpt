--TEST--
stdlib: sort() on null throws Error could not be passed by reference (VM, #4333, ext/standard/array.c)
--FILE--
<?php
error_reporting(0);
$fns = ['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort', 'usort', 'uasort', 'uksort'];
foreach ($fns as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (Error $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECTF--
%A
sort: sort(): Argument #1 ($array) could not be passed by reference
rsort: rsort(): Argument #1 ($array) could not be passed by reference
asort: asort(): Argument #1 ($array) could not be passed by reference
arsort: arsort(): Argument #1 ($array) could not be passed by reference
ksort: ksort(): Argument #1 ($array) could not be passed by reference
krsort: krsort(): Argument #1 ($array) could not be passed by reference
usort: usort(): Argument #1 ($array) could not be passed by reference
uasort: uasort(): Argument #1 ($array) could not be passed by reference
uksort: uksort(): Argument #1 ($array) could not be passed by reference

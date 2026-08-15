--TEST--
stdlib vfscanf() phantom on default profile — absent from php-src (#26758, re-#6174)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('vfscanf') ? "fn-fail\n" : "fn-ok\n";
$defs = get_defined_functions()['internal'];
echo in_array('vfscanf', $defs, true) ? "def-fail\n" : "def-ok\n";
echo function_exists('sscanf') ? "sscanf-ok\n" : "sscanf-fail\n";
echo function_exists('fscanf') ? "fscanf-ok\n" : "fscanf-fail\n";
--EXPECT--
fn-ok
def-ok
sscanf-ok
fscanf-ok

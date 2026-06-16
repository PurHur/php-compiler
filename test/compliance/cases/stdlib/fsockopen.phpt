--TEST--
Stdlib: fsockopen() — refused connect + errno/errstr (#8954)
--FILE--
<?php
echo function_exists('fsockopen') ? "fn\n" : "no-fn\n";
$errno = 0;
$errstr = '';
$fp = @fsockopen('127.0.0.1', 9, $errno, $errstr, 1);
var_export($fp);
echo "\n";
echo is_int($errno) && 0 !== $errno ? "errno\n" : "no_errno\n";
echo '' !== $errstr ? "errstr\n" : "no_errstr\n";
--EXPECT--
fn
false
errno
errstr

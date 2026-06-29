--TEST--
stdlib: unlink()/realpath() null path Z_PARAM_PATH coerce (VM, #13406, ext/standard/filestat.c)
--FILE--
<?php
error_reporting(0);
$unlinkOk = @unlink(null);
$realNull = realpath(null);
$realEmpty = realpath('');
$existsNull = file_exists(null);
echo 'unlink:', var_export($unlinkOk, true), "\n";
echo 'realpath_null:', gettype($realNull), ':', ($realNull === $realEmpty ? 'match' : 'mismatch'), "\n";
echo 'file_exists:', var_export($existsNull, true), "\n";
--EXPECTF--
%A
unlink:false
realpath_null:string:match
file_exists:false

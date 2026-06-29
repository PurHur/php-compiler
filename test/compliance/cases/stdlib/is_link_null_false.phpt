--TEST--
stdlib is_link()/is_readable()/is_writable()/is_executable() null path — false not TypeError (#13661, ext/standard/filestat.c)
--FILE--
<?php
var_export(@is_link(null));
echo "\n";
var_export(@is_readable(null));
echo "\n";
var_export(@is_writable(null));
echo "\n";
var_export(@is_executable(null));
echo "\n";
--EXPECT--
false
false
false
false

--TEST--
stdlib chdir() failed path emits E_WARNING (#13456, ext/standard/dir.c)
--FILE--
<?php
$missing = '/nonexistent/chdir_' . getmypid();
echo var_export(chdir($missing), true), "\n";
?>
--EXPECTF--
%A
false

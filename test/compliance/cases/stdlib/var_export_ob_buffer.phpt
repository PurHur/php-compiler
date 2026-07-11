--TEST--
stdlib var_export() echo mode respects ob_start() (#11649, ext/standard/var.c)
--FILE--
<?php
$u = posix_uname();
ob_start();
var_export($u['domainname']);
echo ob_get_clean(), "\n";
--EXPECT--
'(none)'

--TEST--
ext/ftp transfer API present on reference profile (#20083, re-#20033)
--FILE--
<?php
echo 'pasv=', (int) function_exists('ftp_pasv'), "\n";
echo 'get=', (int) function_exists('ftp_get'), "\n";
echo 'connect=', (int) function_exists('ftp_connect'), "\n";
?>
--EXPECT--
pasv=1
get=1
connect=1

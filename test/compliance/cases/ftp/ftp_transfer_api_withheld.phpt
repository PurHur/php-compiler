--TEST--
ext/ftp transfer API withheld on default profile (#20033)
--FILE--
<?php
echo 'pasv=', (int) function_exists('ftp_pasv'), "\n";
echo 'get=', (int) function_exists('ftp_get'), "\n";
echo 'connect=', (int) function_exists('ftp_connect'), "\n";
?>
--EXPECT--
pasv=0
get=0
connect=0

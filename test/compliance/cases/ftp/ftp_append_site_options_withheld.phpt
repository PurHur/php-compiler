--TEST--
ext/ftp append/SITE/options withheld on default profile (#20060)
--FILE--
<?php
echo 'append=', (int) function_exists('ftp_append'), "\n";
echo 'set_option=', (int) function_exists('ftp_set_option'), "\n";
echo 'connect=', (int) function_exists('ftp_connect'), "\n";
?>
--EXPECT--
append=0
set_option=0
connect=0

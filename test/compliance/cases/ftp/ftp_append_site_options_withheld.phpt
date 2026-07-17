--TEST--
ext/ftp append/SITE/options present on reference profile (#20083, re-#20060)
--FILE--
<?php
echo 'append=', (int) function_exists('ftp_append'), "\n";
echo 'set_option=', (int) function_exists('ftp_set_option'), "\n";
echo 'connect=', (int) function_exists('ftp_connect'), "\n";
?>
--EXPECT--
append=1
set_option=1
connect=1

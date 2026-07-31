--TEST--
stdlib extension_loaded('odbc') false without host ext/odbc (#23969)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('odbc'), "\n";
echo 'in_list=', (int) in_array('odbc', get_loaded_extensions(), true), "\n";
echo 'fn=', (int) function_exists('odbc_connect'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
fn=0

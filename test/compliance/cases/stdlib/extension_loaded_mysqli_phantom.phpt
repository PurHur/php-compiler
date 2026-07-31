--TEST--
stdlib extension_loaded('mysqli') false without host ext/mysqli (#23954)
--FILE--
<?php
declare(strict_types=1);
echo 'loaded=', (int) extension_loaded('mysqli'), "\n";
echo 'in_list=', (int) in_array('mysqli', get_loaded_extensions(), true), "\n";
echo 'fn=', (int) function_exists('mysqli_connect'), "\n";
echo 'cls=', (int) class_exists('mysqli'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
fn=0
cls=0

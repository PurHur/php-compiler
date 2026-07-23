--TEST--
ext/enchant enchant_dict_is_in_session alias (#22251)
--SKIPIF--
<?php
if (!function_exists('enchant_dict_is_added')) die('skip no enchant');
?>
--FILE--
<?php
echo 'is_in_session=', var_export(function_exists('enchant_dict_is_in_session'), true), "\n";
echo 'is_added=', var_export(function_exists('enchant_dict_is_added'), true), "\n";
?>
--EXPECT--
is_in_session=true
is_added=true

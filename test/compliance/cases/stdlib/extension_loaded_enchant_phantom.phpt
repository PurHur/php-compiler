--TEST--
stdlib extension_loaded('enchant') false without host ext/enchant (#23963, ext/enchant/enchant.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('enchant'), "\n";
echo 'in_list=', (int) in_array('enchant', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('enchant')), "\n";
echo 'enchant_broker_init=', (int) function_exists('enchant_broker_init'), "\n";
echo 'enchant_dict_check=', (int) function_exists('enchant_dict_check'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
enchant_broker_init=0
enchant_dict_check=0

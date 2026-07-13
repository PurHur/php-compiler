--TEST--
stdlib extension_loaded('wddx') withheld on reference profile (#6327, ext/wddx/wddx.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('wddx'), "\n";
echo 'in_list=', (int) in_array('wddx', get_loaded_extensions(), true), "\n";
echo 'serialize=', (int) function_exists('wddx_serialize_value'), "\n";
echo 'deserialize=', (int) function_exists('wddx_deserialize'), "\n";
--EXPECT--
loaded=0
in_list=0
serialize=0
deserialize=0

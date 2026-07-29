--TEST--
stdlib extension_loaded('uuid') false without host pecl-uuid / forward profile (#23962, pecl-networking-uuid)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('uuid'), "\n";
echo 'in_list=', (int) in_array('uuid', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('uuid')), "\n";
echo 'uuid_create=', (int) function_exists('uuid_create'), "\n";
echo 'uuid_generate=', (int) function_exists('uuid_generate'), "\n";
echo 'UUID_TYPE_RANDOM=', (int) defined('UUID_TYPE_RANDOM'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
uuid_create=0
uuid_generate=0
UUID_TYPE_RANDOM=0

--TEST--
stdlib extension_loaded('curl') false until curl_init implemented (#11627, #11654, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'in_list=', (int) in_array('curl', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) function_exists('curl_init'), "\n";
--EXPECT--
loaded=0
in_list=0
funcs=0

--TEST--
stdlib extension_loaded('curl') true when libcurl easy API ships (#11627, #3325, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'in_list=', (int) in_array('curl', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) function_exists('curl_init'), "\n";
--EXPECT--
loaded=1
in_list=1
funcs=1

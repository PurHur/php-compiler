--TEST--
stdlib extension_loaded('simdjson') withheld on reference profile (#22530)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('simdjson'), "\n";
echo 'in_list=', (int) in_array('simdjson', get_loaded_extensions(), true), "\n";
echo 'decode=', (int) function_exists('simdjson_decode'), "\n";
echo 'valid=', (int) function_exists('simdjson_is_valid'), "\n";
--EXPECT--
loaded=0
in_list=0
decode=0
valid=0

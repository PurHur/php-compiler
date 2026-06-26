--TEST--
stdlib extension_loaded('msgpack') false until pack/unpack implemented (#11986, ext/msgpack/msgpack.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('msgpack'), "\n";
echo 'in_list=', (int) in_array('msgpack', get_loaded_extensions(), true), "\n";
echo 'pack=', (int) function_exists('msgpack_pack'), "\n";
echo 'unpack=', (int) function_exists('msgpack_unpack'), "\n";
--EXPECT--
loaded=0
in_list=0
pack=0
unpack=0

--TEST--
stdlib extension_loaded('igbinary') false until serialize implemented (#11993, ext/igbinary/igbinary.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('igbinary'), "\n";
echo 'in_list=', (int) in_array('igbinary', get_loaded_extensions(), true), "\n";
echo 'serialize=', (int) function_exists('igbinary_serialize'), "\n";
echo 'unserialize=', (int) function_exists('igbinary_unserialize'), "\n";
echo 'pack=', (int) function_exists('igbinary_pack'), "\n";
echo 'unpack=', (int) function_exists('igbinary_unpack'), "\n";
--EXPECT--
loaded=0
in_list=0
serialize=0
unserialize=0
pack=0
unpack=0

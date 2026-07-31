--TEST--
stdlib extension_loaded('lz4') false without host pecl-lz4 (#25087)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('lz4'), "\n";
echo 'in_list=', (int) in_array('lz4', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('lz4')), "\n";
echo 'lz4_compress=', (int) function_exists('lz4_compress'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
lz4_compress=0

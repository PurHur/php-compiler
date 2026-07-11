--TEST--
stdlib extension_loaded('gd') false until libgd implemented (#11675, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('gd'), "\n";
echo 'in_list=', (int) in_array('gd', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) function_exists('imagecreate'), "\n";
echo 'class=', (int) class_exists('GdImage'), "\n";
--EXPECT--
loaded=0
in_list=0
funcs=0
class=0

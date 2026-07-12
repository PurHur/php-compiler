--TEST--
stdlib extension_loaded('gd') true when decode builtins ship (#6215, #11675, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('gd'), "\n";
echo 'in_list=', (int) in_array('gd', get_loaded_extensions(), true), "\n";
echo 'decode=', (int) function_exists('imagecreatefromstring'), "\n";
echo 'draw=', (int) function_exists('imagecreate'), "\n";
echo 'class=', (int) class_exists('GdImage'), "\n";
--EXPECT--
loaded=1
in_list=1
decode=1
draw=1
class=1

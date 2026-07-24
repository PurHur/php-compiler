--TEST--
stdlib extension_loaded('gd') false without host php-gd (#22740, re-#11675, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('gd'), "\n";
echo 'in_list=', (int) in_array('gd', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('gd')), "\n";
echo 'gd_info=', (int) function_exists('gd_info'), "\n";
echo 'imagecreate=', (int) function_exists('imagecreate'), "\n";
echo 'imagecreatefromstring=', (int) function_exists('imagecreatefromstring'), "\n";
echo 'GdImage=', (int) class_exists('GdImage', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
gd_info=0
imagecreate=0
imagecreatefromstring=0
GdImage=0

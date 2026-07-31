--TEST--
stdlib extension_loaded('ds') false without host pecl-ds (#25086)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('ds'), "\n";
echo 'in_list=', (int) in_array('ds', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('ds')), "\n";
echo 'Vector=', (int) class_exists('Ds\\Vector', false), "\n";
echo 'Map=', (int) class_exists('Ds\\Map', false), "\n";
echo 'Set=', (int) class_exists('Ds\\Set', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
Vector=0
Map=0
Set=0

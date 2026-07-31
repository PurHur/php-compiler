--TEST--
stdlib extension_loaded('gnupg') false without host pecl-gnupg (#25360)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('gnupg'), "\n";
echo 'in_list=', (int) in_array('gnupg', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('gnupg')), "\n";
echo 'fn=', (int) function_exists('gnupg_init'), "\n";
echo 'cls=', (int) class_exists('gnupg', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
fn=0
cls=0

--TEST--
stdlib extension_loaded('pspell') false without host ext/pspell (#23968, ext/pspell/pspell.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('pspell'), "\n";
echo 'in_list=', (int) in_array('pspell', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('pspell')), "\n";
echo 'pspell_new=', (int) function_exists('pspell_new'), "\n";
echo 'pspell_check=', (int) function_exists('pspell_check'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
pspell_new=0
pspell_check=0

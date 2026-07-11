--TEST--
stdlib readline extension_loaded matches function_exists (#12132, ext/readline/readline.c)
--FILE--
<?php
declare(strict_types=1);

echo 'fe=', (int) function_exists('readline'), "\n";
echo 'loaded=', (int) extension_loaded('readline'), "\n";
echo 'in_list=', (int) in_array('readline', get_loaded_extensions(), true), "\n";
--EXPECT--
fe=1
loaded=1
in_list=1

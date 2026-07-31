--TEST--
stdlib extension_loaded('dba') false without host ext/dba (#24134)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('dba'), "\n";
echo 'in_list=', (int) in_array('dba', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('dba')), "\n";
echo 'fn=', (int) function_exists('dba_open'), "\n";
echo 'conn=', (int) class_exists('Dba\\Connection', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
fn=0
conn=0

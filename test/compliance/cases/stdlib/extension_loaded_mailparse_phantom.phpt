--TEST--
stdlib extension_loaded('mailparse') false without host pecl-mailparse (#24908)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('mailparse'), "\n";
echo 'in_list=', (int) in_array('mailparse', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('mailparse')), "\n";
echo 'fn=', (int) function_exists('mailparse_msg_create'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
fn=0

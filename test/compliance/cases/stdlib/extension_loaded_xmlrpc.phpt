--TEST--
stdlib extension_loaded('xmlrpc') withheld on reference profile (#18503, ext/xmlrpc/xmlrpc.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('xmlrpc'), "\n";
echo 'in_list=', (int) in_array('xmlrpc', get_loaded_extensions(), true), "\n";
echo 'encode=', (int) function_exists('xmlrpc_encode'), "\n";
echo 'decode=', (int) function_exists('xmlrpc_decode'), "\n";
--EXPECT--
loaded=0
in_list=0
encode=0
decode=0

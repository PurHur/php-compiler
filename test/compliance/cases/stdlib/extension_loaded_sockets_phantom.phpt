--TEST--
stdlib extension_loaded('sockets') true once socket_create() lands (#11820, #19286)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('sockets'), "\n";
echo 'in_list=', (int) in_array('sockets', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) function_exists('socket_create'), "\n";
--EXPECT--
loaded=1
in_list=1
funcs=1

--TEST--
stdlib extension_loaded('sockets') false until socket_create() implemented (#11820, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('sockets'), "\n";
echo 'in_list=', (int) in_array('sockets', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) function_exists('socket_create'), "\n";
--EXPECT--
loaded=0
in_list=0
funcs=0

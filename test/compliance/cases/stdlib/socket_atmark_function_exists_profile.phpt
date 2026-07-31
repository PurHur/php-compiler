--TEST--
stdlib socket_atmark() — withheld on default 8.4.0-dev reference (#25874, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'default=', var_export(function_exists('socket_atmark'), true), "\n";
echo 'sockets=', var_export(extension_loaded('sockets'), true), "\n";
?>
--EXPECT--
default=false
sockets=true

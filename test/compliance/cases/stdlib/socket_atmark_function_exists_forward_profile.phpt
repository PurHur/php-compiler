--TEST--
stdlib socket_atmark() — function_exists on PHP_COMPILER_PROFILE=8.3 (#25874, ext/sockets/sockets.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);

echo 'forward=', var_export(function_exists('socket_atmark'), true), "\n";
?>
--EXPECT--
forward=true

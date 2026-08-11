--TEST--
stdlib socket_clear_error/last_error(null) process errno (#30267, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
try {
    socket_clear_error(null);
    echo "clear:OK\n";
} catch (Throwable $e) {
    echo 'clear:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $e = socket_last_error(null);
    echo 'last:', gettype($e), ':', (int) $e, "\n";
} catch (Throwable $ex) {
    echo 'last:', get_class($ex), ':', $ex->getMessage(), "\n";
}
// Explicit null matches omitting the arg (process errno)
socket_clear_error();
echo 'omit:', (int) socket_last_error(), "\n";
--EXPECT--
clear:OK
last:integer:0
omit:0

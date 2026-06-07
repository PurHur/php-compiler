--TEST--
stdlib stream_set_timeout() JIT — enum case TypeError (#6147)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

$fp = fopen('php://memory', 'r+');

try {
    stream_set_timeout($fp, E::A);
    echo "seconds-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    stream_set_timeout($fp, 1, E::A);
    echo "microseconds-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

fclose($fp);
--EXPECT--
stream_set_timeout(): Argument #2 ($seconds) must be of type int, E given
stream_set_timeout(): Argument #3 ($microseconds) must be of type int, E given

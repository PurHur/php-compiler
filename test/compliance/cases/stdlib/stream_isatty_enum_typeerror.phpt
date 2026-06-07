--TEST--
stream_isatty() enum case stream operand TypeError (#6035, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    stream_isatty(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_isatty(): Argument #1 ($stream) must be of type resource, E given

--TEST--
stream_is_local() enum case stream operand TypeError (#6173, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    stream_is_local(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_is_local(): Argument #1 ($stream) must be of type resource, E given

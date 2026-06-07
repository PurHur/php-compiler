--TEST--
stream_isatty() non-resource operand TypeError (#6035, php-src streamsfuncs.c)
--FILE--
<?php
try {
    stream_isatty([]);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_isatty(): Argument #1 ($stream) must be of type resource, array given

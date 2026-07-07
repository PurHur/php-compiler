--TEST--
stdlib fopen(null) under strict_types JIT throws TypeError (#17073, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

try {
    fopen(null, 'r');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
fopen(): Argument #1 ($filename) must be of type string, null given

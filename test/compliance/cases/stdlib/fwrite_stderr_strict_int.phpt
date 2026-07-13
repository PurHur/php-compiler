--TEST--
stdlib fwrite(STDERR, int) under strict_types — TypeError (#18534, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

try {
    fwrite(STDERR, 42);
    echo "no_error\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}

echo fwrite(STDERR, 'ok'), "\n";
--EXPECT--
TypeError
2

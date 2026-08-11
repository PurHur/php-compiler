--TEST--
stdlib stream_get_contents null $offset TypeError under strict_types (#30249, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r');
try {
    stream_get_contents($f, null, null);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:stream_get_contents(): Argument #3 ($offset) must be of type int, null given

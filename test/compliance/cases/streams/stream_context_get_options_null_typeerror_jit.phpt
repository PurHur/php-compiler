--TEST--
stream_context_get_options(null) TypeError names $stream_or_context JIT (#30418, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
try {
    stream_context_get_options(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:stream_context_get_options(): Argument #1 ($stream_or_context) must be of type resource, null given

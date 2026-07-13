--TEST--
stdlib stream_get_contents(null) — TypeError JIT (#18712, ext/standard/streams.c)
--JIT--
--FILE--
<?php
try {
    stream_get_contents(null);
    echo "no_throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_get_contents(): Argument #1 ($stream) must be of type resource, null given

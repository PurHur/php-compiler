--TEST--
stdlib stream_get_contents(null) — TypeError not LogicException (#18712, ext/standard/streams.c)
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

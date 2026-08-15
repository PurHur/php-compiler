--TEST--
stdlib stream_set_timeout(null $seconds) JIT TypeError under strict_types (#31263, ext/standard/streamsfuncs.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    stream_set_timeout(STDIN, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_set_timeout(): Argument #2 ($seconds) must be of type int, null given

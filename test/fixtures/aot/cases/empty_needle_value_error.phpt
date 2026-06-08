--TEST--
AOT: explode empty separator throws ValueError (#4279)
--FILE--
<?php
try {
    explode('', 'a');
    echo "ok\n";
} catch (ValueError $e) {
    echo "ValueError\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ValueError

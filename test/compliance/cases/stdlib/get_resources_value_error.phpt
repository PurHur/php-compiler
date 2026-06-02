--TEST--
stdlib get_resources(): invalid type throws ValueError (#3646)
--FILE--
<?php
get_resources();
try {
    get_resources('not-a-resource-type');
    echo "no-error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ValueError

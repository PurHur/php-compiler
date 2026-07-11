--TEST--
stdlib implode()/join() — separator TypeError documents array|string union (#16215, ext/standard/string.c)
--FILE--
<?php
try {
    implode(new stdClass(), []);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(new stdClass(), []);
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type array|string, stdClass given
join(): Argument #1 ($separator) must be of type array|string, stdClass given

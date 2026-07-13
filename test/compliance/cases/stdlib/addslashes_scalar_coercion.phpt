--TEST--
stdlib addslashes()/stripslashes() coerce scalars (#4553, #18483, ext/standard/string.c)
--FILE--
<?php
try {
    addslashes(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo stripslashes(123), "\n";
try {
    $unused = addslashes([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
uncaught
123
TypeError: addslashes(): Argument #1 ($string) must be of type string, array given

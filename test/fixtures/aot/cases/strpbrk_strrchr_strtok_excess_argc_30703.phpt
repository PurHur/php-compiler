--TEST--
AOT: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703)
--FILE--
<?php
try {
    strpbrk('abc', 'b', 'x');
    echo "strpbrk:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strpbrk:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strrchr('abc', 'b', 'x');
    echo "strrchr:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strrchr:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strtok('a b', ' ', 'x');
    echo "strtok:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strtok:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(strpbrk('abc', 'b'), true), "\n";
--EXPECT--
strpbrk:ArgumentCountError:strpbrk() expects exactly 2 arguments, 3 given
strrchr:ArgumentCountError:strrchr() expects exactly 2 arguments, 3 given
strtok:ArgumentCountError:strtok() expects at most 2 arguments, 3 given
ok:'bc'

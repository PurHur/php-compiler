--TEST--
stdlib JIT: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703)
--FILE--
<?php
foreach (['strpbrk', 'strrchr'] as $fn) {
    try {
        $fn('abc', 'b', 'x');
        echo "$fn:NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    strtok('a b', ' ', 'x');
    echo "strtok:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strtok:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(strpbrk('abc', 'b'), true), "\n";
echo 'ok:', var_export(strrchr('abc', 'b'), true), "\n";
echo 'ok:', var_export(strtok('a b', ' '), true), "\n";
--EXPECT--
strpbrk:ArgumentCountError:strpbrk() expects exactly 2 arguments, 3 given
strrchr:ArgumentCountError:strrchr() expects exactly 2 arguments, 3 given
strtok:ArgumentCountError:strtok() expects at most 2 arguments, 3 given
ok:'bc'
ok:'bc'
ok:'a'

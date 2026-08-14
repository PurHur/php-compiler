--TEST--
language: Closure::fromCallable excess argc → ArgumentCountError (#30930, Zend/zend_closures.c)
--FILE--
<?php
try {
    $c = Closure::fromCallable('strlen', 'x');
    echo 'extra ACCEPTED:', $c('ab'), "\n";
} catch (Throwable $e) {
    echo 'extra ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $c = Closure::fromCallable();
    echo "zero ACCEPTED\n";
} catch (Throwable $e) {
    echo 'zero ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', Closure::fromCallable('strlen')('ab'), "\n";
--EXPECT--
extra ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 2 given
zero ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 0 given
ok=2

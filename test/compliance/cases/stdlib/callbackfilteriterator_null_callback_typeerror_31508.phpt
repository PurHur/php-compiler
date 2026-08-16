--TEST--
CallbackFilterIterator::__construct null callback — TypeError in ctor (#31508, ext/spl/spl_iterators.c)
--FILE--
<?php
error_reporting(E_ALL);
try {
    new CallbackFilterIterator(new ArrayIterator([1]), null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: CallbackFilterIterator::__construct(): Argument #2 ($callback) must be a valid callback, no array or string given

--TEST--
CallbackFilterIterator/RecursiveCallbackFilterIterator unknown string callback TypeError (#31574, ext/spl/spl_iterators.c)
--FILE--
<?php
error_reporting(E_ALL);
try {
    new CallbackFilterIterator(new ArrayIterator([1]), 'not_callable');
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new RecursiveCallbackFilterIterator(new RecursiveArrayIterator([1]), 'missing_fn');
    echo "no_throw2\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: CallbackFilterIterator::__construct(): Argument #2 ($callback) must be a valid callback, function "not_callable" not found or invalid function name
TypeError: RecursiveCallbackFilterIterator::__construct(): Argument #2 ($callback) must be a valid callback, function "missing_fn" not found or invalid function name

--TEST--
stdlib wordwrap() JIT — float and numeric-string width coercion (issue #4212)
--JIT--
--FILE--
<?php
echo wordwrap('hello', 1.9), "\n";
echo wordwrap('hello world', 3.7), "\n";
echo wordwrap('hello world', '3'), "\n";
try {
    wordwrap('hello', 'abc');
    echo "invalid string uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    wordwrap('hello', []);
    echo "array uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
hello
hello
world
hello
world
TypeError: wordwrap(): Argument #2 ($width) must be of type int, string given
TypeError: wordwrap(): Argument #2 ($width) must be of type int, array given

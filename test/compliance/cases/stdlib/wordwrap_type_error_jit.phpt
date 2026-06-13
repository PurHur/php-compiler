--TEST--
stdlib wordwrap() JIT — TypeError for non-string operand (#4579)
--FILE--
<?php
try {
    $unused = wordwrap([]);
    echo "array uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $unused = wordwrap(new stdClass());
    echo "object uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo wordwrap('hello world', 5), "\n";
--EXPECT--
TypeError: wordwrap(): Argument #1 ($string) must be of type string, array given
TypeError: wordwrap(): Argument #1 ($string) must be of type string, stdClass given
hello
world

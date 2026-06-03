--TEST--
stdlib ucwords() JIT — TypeError for non-string operands (#4950)
--FILE--
<?php
try {
    $unused = ucwords([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    $unused = ucwords('hello', new stdClass());
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo ucwords('hello world'), "\n";
--EXPECT--
TypeError: ucwords(): Argument #1 ($string) must be of type string, array given
TypeError: ucwords(): Argument #2 ($separators) must be of type string, stdClass given
Hello World

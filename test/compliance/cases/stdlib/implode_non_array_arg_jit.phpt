--TEST--
stdlib implode() JIT — TypeError when array argument is not array (#4906)
--FILE--
<?php
try {
    $unused = implode(',', 'x');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo implode(',', ['a', 'b']), "\n";
--EXPECT--
TypeError: implode(): Argument #2 ($array) must be of type ?array, string given
a,b

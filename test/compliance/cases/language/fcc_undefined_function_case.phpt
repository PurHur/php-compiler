--TEST--
language: first-class callable undefined-function Error preserves identifier case (#26690, zend_execute_API.c)
--FILE--
<?php
try {
    $f = FooBar(...);
    echo "fcc uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
// Lookup remains case-insensitive when the function exists.
function MixedCaseFn() { return 7; }
$ok = mixedcasefn(...);
echo $ok(), "\n";
// Dynamic $fn() also preserves the callable string spelling.
$fn = 'FooBar';
try {
    $fn();
    echo "dynamic uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to undefined function FooBar()
7
Error: Call to undefined function FooBar()

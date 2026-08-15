--TEST--
stdlib settype(null) $type — soft-null DEP then ValueError (#30506, ext/standard/type.c)
--FILE--
<?php
error_reporting(E_ALL);
$a = 1;
try {
    settype($a, null);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECTF--
PHP Deprecated:  settype(): Passing null to parameter #2 ($type) of type string is deprecated in %s on line %d
ValueError: settype(): Argument #2 ($type) must be a valid type

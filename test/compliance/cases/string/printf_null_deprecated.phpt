--TEST--
stdlib printf(null) — E_DEPRECATED not TypeError (#18764, ext/standard/formatted_io.c)
--FILE--
<?php
error_reporting(E_ALL);
try {
    printf(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECTF--
PHP Deprecated:  printf(): Passing null to parameter #1 ($format) of type string is deprecated in %s on line %d
ok

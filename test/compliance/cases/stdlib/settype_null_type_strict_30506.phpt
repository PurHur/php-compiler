--TEST--
stdlib settype(null) under strict_types — TypeError like Zend (#30506, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$a = 1;
try {
    settype($a, null);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: settype(): Argument #2 ($type) must be of type string, null given

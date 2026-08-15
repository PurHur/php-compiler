--TEST--
mbstring mb_split(null $limit) TypeError under strict_types (#31312, php_mbregex.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    mb_split('X', 'aXbXcXd', null);
    echo "FAIL: limit coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError
mb_split(): Argument #3 ($limit) must be of type int, null given

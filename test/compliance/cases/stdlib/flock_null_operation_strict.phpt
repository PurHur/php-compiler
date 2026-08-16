--TEST--
stdlib flock() null $operation under strict_types — TypeError (#31462)
--FILE--
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    fclose($fp);
}
--EXPECT--
TypeError: flock(): Argument #2 ($operation) must be of type int, null given

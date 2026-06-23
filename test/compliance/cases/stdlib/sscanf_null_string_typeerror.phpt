--TEST--
stdlib sscanf() rejects null $string (#10920, ext/standard/sscanf.c)
--FILE--
<?php
declare(strict_types=1);
try {
    sscanf(null, '%d');
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: sscanf(): Argument #1 ($string) must be of type string, null given

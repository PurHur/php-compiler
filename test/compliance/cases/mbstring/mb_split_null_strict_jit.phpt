--TEST--
JIT mbstring mb_split() - null $pattern TypeError under strict_types (#29811)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    mb_split(null, 'a');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
mb_split(): Argument #1 ($pattern) must be of type string, null given

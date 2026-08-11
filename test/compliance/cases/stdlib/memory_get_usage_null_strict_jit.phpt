--TEST--
JIT: memory_get_usage/peak_usage(null) under strict_types TypeError (#30346)
--FILE--
<?php
declare(strict_types=1);

foreach (['memory_get_usage', 'memory_get_peak_usage'] as $fn) {
    try {
        var_export($fn(null));
        echo " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
memory_get_usage(): Argument #1 ($real_usage) must be of type bool, null given
memory_get_peak_usage(): Argument #1 ($real_usage) must be of type bool, null given

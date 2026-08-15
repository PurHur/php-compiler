--TEST--
filter_var null $options TypeError under strict_types JIT (#31209)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(filter_var('1', FILTER_VALIDATE_INT, null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
filter_var(): Argument #3 ($options) must be of type array|int, null given

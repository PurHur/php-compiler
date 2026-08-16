--TEST--
filter_var_array null $options TypeError under strict_types JIT (#31490)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(filter_var_array(['a' => '1'], null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
filter_var_array(): Argument #2 ($options) must be of type array|int, null given

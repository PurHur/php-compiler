--TEST--
filter_input/filter_has_var/filter_input_array null args TypeError under strict_types JIT (#29776)
--FILE--
<?php
declare(strict_types=1);

foreach (['filter_input', 'filter_has_var', 'filter_input_array'] as $name) {
    try {
        if ('filter_input' === $name) {
            filter_input(INPUT_GET, null);
        } elseif ('filter_has_var' === $name) {
            filter_has_var(INPUT_GET, null);
        } else {
            filter_input_array(INPUT_GET, null);
        }
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
filter_input(): Argument #2 ($var_name) must be of type string, null given
filter_has_var(): Argument #2 ($var_name) must be of type string, null given
filter_input_array(): Argument #2 ($options) must be of type array|int, null given

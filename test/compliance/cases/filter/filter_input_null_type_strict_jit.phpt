--TEST--
filter_has_var/filter_input null $type TypeError under strict_types JIT (#31486)
--FILE--
<?php
declare(strict_types=1);

foreach (['filter_has_var', 'filter_input'] as $name) {
    try {
        if ('filter_has_var' === $name) {
            filter_has_var(null, 'x');
        } else {
            filter_input(null, 'x');
        }
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
filter_has_var(): Argument #1 ($input_type) must be of type PhpInputFilter|int, null given
filter_input(): Argument #1 ($type) must be of type PhpInputFilter|int, null given

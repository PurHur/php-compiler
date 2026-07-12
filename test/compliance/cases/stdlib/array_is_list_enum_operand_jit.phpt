--TEST--
stdlib array_is_list() JIT — enum case operand TypeError names enum type (#9210, ext/standard/type.c)
--FILE--
<?php
enum Color: string {
    case Red = 'red';
}

foreach ([null, Color::Red] as $bad) {
    try {
        array_is_list($bad);
        echo "no throw for ", get_debug_type($bad), "\n";
    } catch (TypeError $e) {
        echo get_debug_type($bad), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
null: array_is_list(): Argument #1 ($array) must be of type array, null given
Color: array_is_list(): Argument #1 ($array) must be of type array, Color given

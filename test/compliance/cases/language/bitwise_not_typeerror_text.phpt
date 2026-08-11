--TEST--
Bitwise NOT (~) TypeError text matches Zend for array/object/false/true/null (#30139)
--FILE--
<?php

$cases = [
    'array' => fn() => ~[1],
    'object' => fn() => ~(new stdClass),
    'false' => fn() => ~false,
    'true' => fn() => ~true,
    'null' => fn() => ~null,
];

foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label: BUG no error\n";
    } catch (\TypeError $e) {
        echo "$label: " . $e->getMessage() . "\n";
    }
}
--EXPECT--
array: Cannot perform bitwise not on array
object: Cannot perform bitwise not on stdClass
false: Cannot perform bitwise not on false
true: Cannot perform bitwise not on true
null: Cannot perform bitwise not on null

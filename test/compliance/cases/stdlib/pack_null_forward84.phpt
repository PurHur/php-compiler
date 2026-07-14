--TEST--
stdlib pack() null value operands TypeError on 8.4 forward profile (#18992)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'pack H*' => static fn () => pack('H*', null),
    'pack c' => static fn () => pack('c', null),
    'pack a5' => static fn () => pack('a5', null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
echo ord(pack('c', 65)), "\n";
?>
--EXPECT--
pack H*: pack(): Argument #2 ($values) must be of type string, null given
pack c: pack(): Argument #2 ($values) must be of type string, null given
pack a5: pack(): Argument #2 ($values) must be of type string, null given
65

--TEST--
stdlib pack() null value operands coerce on 8.4 forward profile (#21654, #18992)
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
        echo "$label: ok\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
echo ord(pack('c', 65)), "\n";
?>
--EXPECT--
pack H*: ok
pack c: ok
pack a5: ok
65

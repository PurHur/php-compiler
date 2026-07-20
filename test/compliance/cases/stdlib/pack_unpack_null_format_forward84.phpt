--TEST--
stdlib pack()/unpack() null format TypeError; unpack data soft-null on 8.4 (#20241, #21246)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'pack_fmt' => static fn () => pack(null),
    'unpack_data' => static fn () => unpack('a*', null),
    'unpack_fmt' => static fn () => unpack(null, 'x'),
    'pack_val' => static fn () => pack('a*', null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label COERCED\n";
    } catch (TypeError $e) {
        echo "$label TypeError\n";
    }
}
echo bin2hex(pack('a*', 'ok')), "\n";
--EXPECT--
pack_fmt TypeError
unpack_data COERCED
unpack_fmt TypeError
pack_val COERCED
6f6b

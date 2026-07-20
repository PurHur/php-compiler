--TEST--
stdlib pack()/unpack() null format soft-null; unpack data soft-null on 8.4 — JIT (#21478, reverts #20241; #21246)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    'pack_fmt' => static fn () => pack(null),
    'unpack_data' => static fn () => unpack('a*', null),
    'unpack_fmt' => static fn () => unpack(null, 'x'),
    'pack_val' => static fn () => pack('a*', null),
] as $label => $factory) {
    try {
        $r = $factory();
        if ('pack_fmt' === $label) {
            echo "$label OK ", var_export($r, true), "\n";
        } elseif ('unpack_fmt' === $label) {
            echo "$label OK ", var_export($r, true), "\n";
        } else {
            echo "$label COERCED\n";
        }
    } catch (TypeError $e) {
        echo "$label TypeError\n";
    }
}
echo bin2hex(pack('a*', 'ok')), "\n";
--EXPECT--
DEP
pack_fmt OK ''
DEP
unpack_data COERCED
DEP
unpack_fmt OK array (
)
pack_val COERCED
6f6b

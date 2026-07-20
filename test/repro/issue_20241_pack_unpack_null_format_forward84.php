/**
 * #20241 / #21246 — pack()/unpack() null under PHP_COMPILER_PROFILE=8.4.
 *
 * $format remains Z_PARAM_STR TypeError; unpack $string / pack values soft-null (#21246, #21209).
 */
error_reporting(E_ALL);
foreach ([
    'pack_fmt' => static fn () => pack(null),
    'unpack_data' => static fn () => unpack('a*', null),
    'unpack_fmt' => static fn () => unpack(null, 'x'),
    'pack_val' => static fn () => pack('a*', null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo $label, ' COERCED ', json_encode($r), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}

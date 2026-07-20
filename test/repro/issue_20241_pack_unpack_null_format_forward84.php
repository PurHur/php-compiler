/**
 * #21478 — pack()/unpack() null $format soft-null under PHP_COMPILER_PROFILE=8.4
 * (reverts #20241 TypeError; unpack $string / pack values still soft #21246/#21209).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return true;
});
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

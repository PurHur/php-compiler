<?php

/**
 * #20241 — pack()/unpack() null format/data under PHP_COMPILER_PROFILE=8.4.
 *
 * Expect TypeError (Z_PARAM_STR); default profile still deprecate+coerce.
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

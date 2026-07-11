<?php

declare(strict_types=1);

if (!extension_loaded('msgpack')) {
    echo "fail: extension_loaded('msgpack') false on reference profile\n";
    exit(1);
}

foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists('{$fn}') false on reference profile\n";
        exit(1);
    }
}

$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello'];
$packed = msgpack_pack($data);
if (!is_string($packed) || msgpack_unpack($packed) !== $data) {
    echo "fail: msgpack round-trip\n";
    exit(1);
}

echo "ok\n";

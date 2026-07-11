<?php

declare(strict_types=1);

if (!extension_loaded('msgpack')) {
    echo "fail: extension_loaded('msgpack') false\n";
    exit(1);
}

foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists('{$fn}') false\n";
        exit(1);
    }
}

$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello', 'd' => true, 'e' => null, 'f' => 1.5];
$packed = msgpack_pack($data);
$unpacked = msgpack_unpack($packed);
if ($unpacked !== $data) {
    echo "fail: round-trip mismatch\n";
    exit(1);
}

echo "ok\n";

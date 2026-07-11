<?php

declare(strict_types=1);

if (extension_loaded('msgpack')) {
    echo "fail: extension_loaded('msgpack') true on reference profile\n";
    exit(1);
}

foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists('{$fn}') true on reference profile\n";
        exit(1);
    }
}

echo "ok\n";

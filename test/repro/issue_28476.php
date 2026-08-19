<?php

declare(strict_types=1);

function tryCall(string $label, callable $fn): void {
    try {
        $fn();
    } catch (\ArgumentCountError $e) {
        echo "$label:ArgumentCountError:{$e->getMessage()}\n";
    }
}

// ceil
tryCall('ceil/0', function () { ceil(); });
tryCall('ceil/2', function () { ceil(1.5, 2); });
echo 'ceil_ok:' . ceil(1.5) . "\n";

// floor
tryCall('floor/0', function () { floor(); });
echo 'floor_ok:' . floor(1.5) . "\n";

// bindec
tryCall('bindec/0', function () { bindec(); });
echo 'bindec_ok:' . bindec('1010') . "\n";

// hexdec
tryCall('hexdec/2', function () { hexdec('ff', 'extra'); });
echo 'hexdec_ok:' . hexdec('ff') . "\n";

// random_bytes
tryCall('random_bytes/0', function () { random_bytes(); });

// random_int
tryCall('random_int/0', function () { random_int(); });
tryCall('random_int/3', function () { random_int(1, 2, 3); });

// password_verify
tryCall('password_verify/0', function () { password_verify(); });
tryCall('password_verify/3', function () { password_verify('a', 'b', 'c'); });

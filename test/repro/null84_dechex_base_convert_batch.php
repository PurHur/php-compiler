<?php

error_reporting(E_ALL);
set_error_handler(static function (): bool {
    return true;
});

$cases = [
    'dechex' => static fn () => dechex(null),
    'decbin' => static fn () => decbin(null),
    'decoct' => static fn () => decoct(null),
    'hexdec' => static fn () => hexdec(null),
    'bindec' => static fn () => bindec(null),
    'octdec' => static fn () => octdec(null),
    'base_convert' => static fn () => base_convert(null, 10, 16),
];

foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        if (\is_int($r)) {
            echo $name, ': ', $r, "\n";
        } else {
            echo $name, ': ', var_export($r, true), "\n";
        }
    } catch (TypeError $e) {
        echo $name, ': TypeError ', $e->getMessage(), "\n";
    }
}

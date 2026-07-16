<?php
/** Repro #19283 — base64_decode/hex2bin/quoted_printable_* null TypeError on PHP_COMPILER_PROFILE=8.4. */
foreach ([
    'base64_decode' => static fn () => base64_decode(null),
    'hex2bin' => static fn () => hex2bin(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
    'quoted_printable_decode' => static fn () => quoted_printable_decode(null),
] as $label => $factory) {
    try {
        $factory();
        echo "{$label}: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
echo "ok\n";

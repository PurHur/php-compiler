<?php
/** Repro #21188 / re-#19283 — base64_decode soft-null; hex2bin still TypeError on PROFILE=8.4. */
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach ([
    'base64_decode' => static fn () => base64_decode(null),
    'hex2bin' => static fn () => hex2bin(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
    'quoted_printable_decode' => static fn () => quoted_printable_decode(null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "{$label}: ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': TypeError'."\n";
    }
}
echo "ok\n";

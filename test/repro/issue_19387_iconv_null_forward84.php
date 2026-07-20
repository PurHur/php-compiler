<?php
// Repro #19387 / #21197 — iconv null under PROFILE=8.4 (encodings TypeError; string soft-null)
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach ([
    'enc_from' => static fn () => iconv(null, 'UTF-8', 'x'),
    'enc_to' => static fn () => iconv('UTF-8', null, 'x'),
    'string' => static fn () => iconv('UTF-8', 'UTF-8', null),
] as $label => $fn) {
    try {
        $result = $fn();
        echo $label, '=', var_export($result, true), "\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), "\n";
    }
}

<?php
// Repro #21313 — mb case/search null soft-forward on PROFILE=8.4 (php-src ext/mbstring/mbstring.stub.php).
error_reporting(E_ALL);
$depr = 0;
set_error_handler(static function (int $no) use (&$depr): bool {
    if (E_DEPRECATED === $no) {
        ++$depr;
    }

    return true;
});
$cases = [
    'mb_strtoupper' => static fn () => mb_strtoupper(null),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_strstr' => static fn () => mb_strstr(null, 'a'),
    'mb_stristr' => static fn () => mb_stristr(null, 'a'),
    'mb_strrchr' => static fn () => mb_strrchr(null, 'a'),
    'mb_stripos' => static fn () => mb_stripos(null, 'a'),
    'mb_strrpos' => static fn () => mb_strrpos(null, 'a'),
    'mb_strripos' => static fn () => mb_strripos(null, 'a'),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
    } catch (TypeError $e) {
        echo "FAIL $name: TypeError\n";
        exit(1);
    }
}
restore_error_handler();
if ($depr < \count($cases)) {
    echo "FAIL: expected deprecation for each case, got $depr\n";
    exit(1);
}
echo "OK\n";

<?php
// Repro #19297 / #21197 — mbstring null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
$cases = [
    'mb_strlen' => static fn () => mb_strlen(null),
    'mb_substr' => static fn () => mb_substr(null, 0),
    'mb_strpos' => static fn () => mb_strpos(null, 'a'),
    'mb_strtolower' => static fn () => mb_strtolower(null),
    'mb_strtoupper' => static fn () => mb_strtoupper(null),
    'mb_convert_encoding' => static fn () => mb_convert_encoding(null, 'UTF-8'),
];
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo "$name: OK ".var_export($r, true)."\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}

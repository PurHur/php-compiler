<?php
// Guard #19297 / #21197 — mbstring null under PROFILE=8.4
// #21197: mb_strlen/mb_substr/mb_strpos soft-null; others still TypeError until follow-ups.
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        return true;
    }

    return false;
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
        echo $name.': OK '.var_export($r, true)."\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}

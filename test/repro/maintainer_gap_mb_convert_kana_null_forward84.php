<?php
// #24209 — mb_convert_kana(null) DEP+coerce under PROFILE=8.4 (Zend, not TypeError)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
$r = mb_convert_kana(null);
$s = "\xEF\xBD\xB1";
$mode = mb_convert_kana($s, null);
restore_error_handler();
$depr = count($seen) >= 2;
$ok = is_string($r) && '' === $r && is_string($mode) && 'efbdb1' === bin2hex($mode) && $depr;
echo 'string=', var_export($r, true), "\n";
echo 'mode=', bin2hex($mode), "\n";
echo 'ok=', (int) $ok, ' depr=', (int) $depr, "\n";

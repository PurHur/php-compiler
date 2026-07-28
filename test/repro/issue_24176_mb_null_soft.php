<?php
// #24176 — VM/AOT probe: mb_* null soft-coerce under PROFILE=8.4 (Zend DEP+coerce)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
// Soft-coerce results (Zend 8.4).
$trim = mb_trim(null);
$uc = mb_ucfirst(null);
$lc = mb_lcfirst(null);
$pad = mb_str_pad(null, 5);
restore_error_handler();
echo var_export($trim, true), "\n";
echo var_export($uc, true), "\n";
echo var_export($lc, true), "\n";
echo var_export($pad, true), "\n";
$ok = '' === $trim && '' === $uc && '' === $lc && '     ' === $pad;
// At least the lowered/pad + ucfirst/lcfirst paths emit DEP; trim may print via CLI stderr first.
echo 'ok=', (int) $ok, ' depr=', (int) (count($seen) >= 3), "\n";

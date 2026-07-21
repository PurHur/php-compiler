<?php
// Repro #21814 — bcdiv()/bcadd() optional $scale null (DEP + default scale) under PROFILE=8.4
$dep = 0;
set_error_handler(static function (int $no, string $msg) use (&$dep): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        ++$dep;

        return true;
    }

    return false;
});
echo 'bcdiv=', bcdiv('1', '3', null), " dep=$dep\n";
$dep = 0;
[$q, $r] = bcdivmod('10', '3', null);
echo "bcdivmod=$q,$r dep=$dep\n";
echo 'bcadd=', bcadd('1', '2', null), " dep=$dep\n";

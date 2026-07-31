<?php
/**
 * Spoofchecker::areConfusable after setChecks(INVISIBLE) → U_INVALID_STATE_ERROR warning (#25209).
 * php-src: ext/intl/spoofchecker/spoofchecker_main.c
 */
error_reporting(E_ALL);
$warned = '';
set_error_handler(static function (int $n, string $s) use (&$warned): bool {
    $warned = $s;
    return true;
});
$s = new Spoofchecker();
$s->setChecks(Spoofchecker::INVISIBLE);
$r = $s->areConfusable('paypal', 'paypa1');
echo 'ret=', $r ? '1' : '0', "\n";
echo 'warn=', $warned, "\n";
echo 'intl=', intl_get_error_code(), ' ', intl_get_error_message(), "\n";
// Valid confusable checks must stay silent
$warned = '';
$s2 = new Spoofchecker();
$r2 = $s2->areConfusable('paypal', 'paypa1');
echo 'default_ret=', $r2 ? '1' : '0', ' warn_empty=', '' === $warned ? '1' : '0', "\n";

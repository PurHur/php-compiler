<?php
/** Repro #29358 — parse_ini_string unterminated quote warning text (php-src zend_ini_scanner.l). */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'WARN:', $errstr, "\n";

    return true;
});

foreach ([
    'dq' => 'a="unterminated',
    'sq' => "a='unterminated",
    'dq_ml' => "a=\"line1\nstill",
] as $label => $ini) {
    echo "== $label ==\n";
    var_export(parse_ini_string($ini));
    echo "\n";
}

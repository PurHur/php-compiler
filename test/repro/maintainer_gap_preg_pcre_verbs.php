<?php

declare(strict_types=1);

/**
 * Issue #12434 — PCRE pattern verbs (*UTF) etc. (ext/pcre/php_pcre.c).
 */
$verbs = [
    '(*UTF)',
    '(*CRLF)',
    '(*ANY)',
    '(*NO_JIT)',
    '(*NOTEMPTY)',
];

foreach ($verbs as $verb) {
    $pattern = '/' . $verb . '/';
    $result = preg_match($pattern, '');
    $err = preg_last_error();
    if (1 !== $result || 0 !== $err) {
        fwrite(STDERR, "verb={$verb} result=".var_export($result, true)." last={$err}\n");
        exit(1);
    }
}

preg_match('/(*INVALID)/', '');
if (1 !== preg_last_error()) {
    fwrite(STDERR, 'invalid verb last=' . preg_last_error() . "\n");
    exit(1);
}

echo 'ok verbs=' . count($verbs) . "\n";

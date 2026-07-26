<?php

declare(strict_types=1);

/**
 * Repro for #22181 — referral iteration helpers.
 */
$fns = [
    'ldap_count_references',
    'ldap_first_reference',
    'ldap_next_reference',
    'ldap_parse_reference',
];
foreach ($fns as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');
try {
    ldap_count_references(42, $link);
    echo "count_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "count_bad_conn_typeerror\n";
}

try {
    ldap_first_reference($link, $link);
    echo "first_bad_result_uncaught\n";
} catch (TypeError $e) {
    echo "first_bad_result_typeerror\n";
}

try {
    $refs = null;
    ldap_parse_reference($link, $link, $refs);
    echo "parse_bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "parse_bad_entry_typeerror\n";
}

ldap_unbind($link);

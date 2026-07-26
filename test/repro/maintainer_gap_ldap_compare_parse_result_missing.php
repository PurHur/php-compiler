<?php

declare(strict_types=1);

/**
 * Repro for #22177 — compare / parse_result / get_dn / attribute helpers.
 */
$fns = [
    'ldap_search',
    'ldap_compare',
    'ldap_parse_result',
    'ldap_get_dn',
    'ldap_first_attribute',
    'ldap_next_attribute',
    'ldap_get_values',
    'ldap_get_values_len',
];
foreach ($fns as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');
try {
    ldap_compare(42, 'cn=x', 'cn', 'x');
    echo "compare_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "compare_bad_conn_typeerror\n";
}

$r = ldap_compare($link, 'cn=x,dc=example,dc=com', 'cn', 'x');
echo (-1 === $r) ? "compare_error_int\n" : (true === $r ? "compare_true\n" : (false === $r ? "compare_false\n" : "compare_other\n"));

$code = 0;
try {
    ldap_parse_result($link, $link, $code);
    echo "parse_bad_result_uncaught\n";
} catch (TypeError $e) {
    echo "parse_bad_result_typeerror\n";
}

ldap_unbind($link);

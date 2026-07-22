<?php
/**
 * Issue #22212 — ldap_dn2ufn / ldap_explode_dn advertisement + Zend shapes.
 */
foreach (['ldap_connect', 'ldap_escape', 'ldap_dn2ufn', 'ldap_explode_dn'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
if (!function_exists('ldap_dn2ufn') || !function_exists('ldap_explode_dn')) {
    fwrite(STDERR, "missing DN helpers\n");
    exit(1);
}
echo 'ufn=', var_export(ldap_dn2ufn('cn=bob,dc=example,dc=com'), true), "\n";
echo 'ex0=', json_encode(ldap_explode_dn('cn=bob,dc=example,dc=com', 0)), "\n";
echo 'ex1=', json_encode(ldap_explode_dn('cn=bob,dc=example,dc=com', 1)), "\n";

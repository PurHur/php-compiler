<?php

declare(strict_types=1);

/**
 * Repro for #22196 — ldap_add/delete/modify/modify_batch + *_ext registration.
 */
$fns = [
    'ldap_connect',
    'ldap_mod_add',
    'ldap_mod_del',
    'ldap_mod_replace',
    'ldap_add',
    'ldap_delete',
    'ldap_modify',
    'ldap_modify_batch',
    'ldap_add_ext',
    'ldap_delete_ext',
    'ldap_rename_ext',
    'ldap_mod_add_ext',
    'ldap_mod_del_ext',
    'ldap_mod_replace_ext',
];
foreach ($fns as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');
try {
    ldap_add(42, 'cn=x', ['cn' => 'a']);
    echo "add_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "add_bad_conn_typeerror\n";
}
try {
    ldap_delete($link, 'cn=missing,dc=example,dc=com');
    echo "delete_callable\n";
} catch (Throwable $e) {
    echo "delete_throw\n";
}
ldap_unbind($link);

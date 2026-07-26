<?php

declare(strict_types=1);

/**
 * Repro for #22164 — ldap_bind_ext registration + Result|false shape.
 */
foreach (['ldap_bind', 'ldap_bind_ext', 'ldap_unbind'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');
try {
    ldap_bind_ext(42);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

$r = ldap_bind_ext($link);
echo false === $r ? "anon_false_or_fail\n" : (is_object($r) ? "anon_result_object\n" : "anon_other\n");
if (is_object($r)) {
    ldap_free_result($r);
}
ldap_unbind($link);

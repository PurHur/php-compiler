<?php

declare(strict_types=1);

/**
 * Repro for #22176 — ldap_sasl_bind after ldap_bind.
 */
echo 'ldap_bind=', function_exists('ldap_bind') ? 'yes' : 'NO', "\n";
echo 'ldap_sasl_bind=', function_exists('ldap_sasl_bind') ? 'yes' : 'NO', "\n";

$link = ldap_connect('ldap://127.0.0.1');
try {
    ldap_sasl_bind(42);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

$r = @ldap_sasl_bind($link, null, null, 'INVALID');
echo false === $r ? "invalid_mech_false\n" : "invalid_mech_other\n";

ldap_unbind($link);

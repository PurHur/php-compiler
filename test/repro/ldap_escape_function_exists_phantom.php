<?php

declare(strict_types=1);

/**
 * When ldap is withheld, ldap_escape must not advertise via function_exists
 * but may still execute if resolved through the internal table (#17680).
 * When libldap FFI advertises (#3369), function_exists is expected true.
 */
if (extension_loaded('ldap') || function_exists('ldap_connect')) {
    if (!function_exists('ldap_escape')) {
        echo "FAIL_MISSING_ESCAPE\n";
        exit(1);
    }
    $escaped = ldap_escape('(a=b)', '', 1);
    if ($escaped !== '\28a=b\29') {
        echo "FAIL_ESCAPE\n";
        exit(1);
    }
    echo "PASS_LDAP_ESCAPE_ADVERTISED\n";
    exit(0);
}

if (function_exists('ldap_escape')) {
    echo "FAIL_ADVERTISED\n";
    exit(1);
}

echo "PASS_LDAP_ESCAPE_PHANTOM\n";

<?php

declare(strict_types=1);

/**
 * When ldap is withheld, ldap_escape must not advertise via function_exists (#17680 / #23857).
 * When host php-ldap or PHP_COMPILER_PROFILE + libldap FFI advertises, escape is callable.
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

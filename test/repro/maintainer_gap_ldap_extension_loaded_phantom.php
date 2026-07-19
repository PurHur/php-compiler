<?php

declare(strict_types=1);

/**
 * #18211 / #3369 — ldap advertisement follows libldap FFI (pgsql honesty).
 * Run: php bin/vm.php test/repro/maintainer_gap_ldap_extension_loaded_phantom.php
 */
$hasConnect = function_exists('ldap_connect');
$hasExt = extension_loaded('ldap');

if ($hasConnect !== $hasExt) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') and function_exists('ldap_connect') disagree\n");
    exit(1);
}

if ($hasConnect) {
    if (!class_exists('LDAP\\Connection')) {
        fwrite(STDERR, "FAIL: LDAP\\Connection missing while ldap_connect advertised\n");
        exit(1);
    }
    echo "ok advertised\n";
    exit(0);
}

if (function_exists('ldap_escape')) {
    fwrite(STDERR, "FAIL: ldap_escape advertised without ldap_connect\n");
    exit(1);
}
echo "ok withheld\n";

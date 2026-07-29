<?php

declare(strict_types=1);

/**
 * #23857 — get_defined_constants(true) must omit the ldap bucket without ext/ldap.
 */
if (extension_loaded('ldap')) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') must be false without host php-ldap\n");
    exit(1);
}

$c = get_defined_constants(true);
if (isset($c['ldap'])) {
    fwrite(STDERR, "FAIL: get_defined_constants(true)['ldap'] must be absent\n");
    exit(1);
}

echo "ok\n";

<?php

declare(strict_types=1);

if (extension_loaded('ldap')) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') must be false without ext/ldap\n");
    exit(1);
}

$c = get_defined_constants(true);
if (isset($c['ldap'])) {
    fwrite(STDERR, "FAIL: get_defined_constants(true) must not expose ldap bucket when ext/ldap unloaded\n");
    exit(1);
}

echo "ok\n";

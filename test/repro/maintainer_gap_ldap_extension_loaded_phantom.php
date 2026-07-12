<?php

declare(strict_types=1);

if (extension_loaded('ldap')) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') must be false without ext/ldap (php-src-strict)\n");
    exit(1);
}

if (function_exists('ldap_escape')) {
    fwrite(STDERR, "FAIL: function_exists('ldap_escape') must be false without ext/ldap\n");
    exit(1);
}

echo "ok\n";

<?php

declare(strict_types=1);

if (!function_exists('ldap_escape')) {
    fwrite(STDERR, "FAIL: function_exists('ldap_escape') must be true when escape builtin is available\n");
    exit(1);
}

if (extension_loaded('ldap')) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') must stay false until full ext/ldap ships\n");
    exit(1);
}

echo "PASS_LDAP_ESCAPE_PHANTOM\n";

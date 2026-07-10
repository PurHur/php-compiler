<?php

declare(strict_types=1);

if (function_exists('ldap_escape')) {
    fwrite(STDERR, "FAIL: ldap_escape must not appear in function_exists() without ext/ldap\n");
    exit(1);
}

echo "ok\n";

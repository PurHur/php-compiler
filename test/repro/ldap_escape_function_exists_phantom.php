<?php

declare(strict_types=1);

if (!function_exists('ldap_escape')) {
    echo "FAIL_EXISTS\n";
    exit(1);
}

$escaped = ldap_escape('(a=b)', '', 1);
if ($escaped !== '\28a=b\29') {
    echo "FAIL_ESCAPE\n";
    exit(1);
}

echo "PASS_LDAP_ESCAPE_PHANTOM\n";

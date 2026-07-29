<?php

declare(strict_types=1);

/**
 * #23857 — ext/ldap must not phantom-advertise on the reference Zend profile.
 * Exit 0 when probes match a host without php-ldap.
 */
if (extension_loaded('ldap')) {
    fwrite(STDERR, "FAIL: extension_loaded('ldap') must be false without host php-ldap\n");
    exit(1);
}

if (function_exists('ldap_connect')) {
    fwrite(STDERR, "FAIL: function_exists('ldap_connect') must be false without host php-ldap\n");
    exit(1);
}

if (in_array('ldap', get_loaded_extensions(), true)) {
    fwrite(STDERR, "FAIL: get_loaded_extensions() must not list ldap\n");
    exit(1);
}

echo "ok\n";

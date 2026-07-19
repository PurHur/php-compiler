<?php

declare(strict_types=1);

/**
 * Issue #3369 — ldap_connect / LDAP\Connection via OpenLDAP FFI.
 */
echo 'ldap_connect=', function_exists('ldap_connect') ? '1' : '0', PHP_EOL;
echo 'extension=', extension_loaded('ldap') ? '1' : '0', PHP_EOL;
echo 'class=', class_exists('LDAP\\Connection') ? '1' : '0', PHP_EOL;

$link = ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    fwrite(STDERR, "FAIL: ldap_connect did not return LDAP\\Connection\n");
    exit(1);
}
ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
@ldap_bind($link);
echo 'errno=', ldap_errno($link), PHP_EOL;
echo 'error=', ldap_error($link), PHP_EOL;
ldap_unbind($link);
echo "ok\n";

<?php

declare(strict_types=1);

/**
 * Issue #20638 — ldap_connect_wallet advertise matches Oracle wallet ABI.
 */
echo 'ldap=', extension_loaded('ldap') ? '1' : '0', PHP_EOL;
echo 'wallet=', function_exists('ldap_connect_wallet') ? '1' : '0', PHP_EOL;
echo 'gslc=', defined('GSLC_SSL_NO_AUTH') ? '1' : '0', PHP_EOL;

if (function_exists('ldap_connect_wallet')) {
    $link = @ldap_connect_wallet('ldap://127.0.0.1', '/tmp/wallet', 'secret', GSLC_SSL_NO_AUTH);
    echo 'connect=', false === $link ? 'false' : 'obj', PHP_EOL;
}
echo "ok\n";

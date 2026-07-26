<?php

declare(strict_types=1);

/**
 * Issue #8688 — ldap_exop* / ldap_parse_exop registration + TypeError guards.
 * ldap_exop_sync / ldap_exop_passwd require PROFILE≥8.3 (#22731).
 */
foreach (['ldap_exop', 'ldap_exop_sync', 'ldap_parse_exop', 'ldap_exop_whoami', 'ldap_exop_refresh', 'ldap_exop_passwd'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}

$link = ldap_connect('ldap://127.0.0.1');
$response = null;
$oid = null;
if (function_exists('ldap_exop_sync')) {
    $ok = @ldap_exop_sync($link, LDAP_EXOP_WHO_AM_I, null, null, $response, $oid);
    echo 'sync_ok=', $ok ? '1' : '0', PHP_EOL;
    echo 'errno_nonzero=', ldap_errno($link) !== 0 ? '1' : '0', PHP_EOL;
} else {
    echo "sync_skipped_profile\n";
}

try {
    ldap_exop(new stdClass(), 'x');
    echo "type_uncaught\n";
} catch (TypeError $e) {
    echo "type_ok\n";
}

try {
    ldap_parse_exop($link, new stdClass());
    echo "parse_type_uncaught\n";
} catch (TypeError $e) {
    echo "parse_type_ok\n";
}

$ttl = @ldap_exop_refresh($link, 'cn=test', 60);
echo 'refresh=', false === $ttl ? 'false' : (string) $ttl, PHP_EOL;
ldap_unbind($link);
echo "ok\n";

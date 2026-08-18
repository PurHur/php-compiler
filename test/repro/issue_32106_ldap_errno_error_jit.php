<?php

declare(strict_types=1);

error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_connect') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

@ldap_bind($link);
echo 'errno=', ldap_errno($link), PHP_EOL;
echo 'error=', ldap_error($link), PHP_EOL;
echo 'err2str=', ldap_err2str(0), PHP_EOL;
echo "ok\n";


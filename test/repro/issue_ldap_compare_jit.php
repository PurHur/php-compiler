<?php

declare(strict_types=1);

// Requires ext/ldap in the compile unit (peer ext/ldap_compare_parse.phpt).
putenv('PHP_COMPILER_ENABLE_LDAP=1');
$_ENV['PHP_COMPILER_ENABLE_LDAP'] = '1';
$_SERVER['PHP_COMPILER_ENABLE_LDAP'] = '1';

error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_compare') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_compare')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

@ldap_bind($link);
set_error_handler(static fn (): bool => true);
try {
    $result = ldap_compare($link, 'cn=x', 'cn', 'x');
} finally {
    restore_error_handler();
}

if (\is_bool($result)) {
    echo 'result=', $result ? 'true' : 'false', PHP_EOL;
} else {
    echo 'result=', $result, PHP_EOL;
}
echo "ok\n";

<?php

// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_socket_dns_errorlog_null_soft_forward84.php
// Expect soft-null DEP+coerce for error_log/gethostbyname/dns_get_record (#24965);
// pfsockopen stays TypeError (#23823). No declare(strict_types=1) — soft-null only outside strict.

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

foreach (
    [
        'error_log' => static fn () => error_log(null),
        'pfsockopen' => static fn () => pfsockopen(null, 80),
        'gethostbyname' => static fn () => gethostbyname(null),
        'dns_get_record' => static fn () => dns_get_record(null),
    ] as $fn => $call
) {
    try {
        $call();
        echo $fn, " COERCED\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";

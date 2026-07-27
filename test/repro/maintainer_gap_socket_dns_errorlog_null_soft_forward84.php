<?php

declare(strict_types=1);

// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_socket_dns_errorlog_null_soft_forward84.php

$checks = [
    'error_log' => static function (): void {
        var_export(error_log(null));
        echo " OK\n";
    },
    'pfsockopen' => static function (): void {
        var_export(pfsockopen(null, 80));
        echo " OK\n";
    },
    'gethostbyname' => static function (): void {
        var_export(gethostbyname(null));
        echo " OK\n";
    },
    'dns_get_record' => static function (): void {
        var_export(dns_get_record(null));
        echo " OK\n";
    },
];

foreach ($checks as $name => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo $name, ': ', get_class($e), "\n";
        exit(1);
    }
}

echo "all ok\n";

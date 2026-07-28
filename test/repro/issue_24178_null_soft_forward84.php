<?php

declare(strict_types=1);

// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24178_null_soft_forward84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/issue24178 test/repro/issue_24178_null_soft_forward84.php

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';

foreach (
    [
        'error_log' => static fn () => error_log(null),
        'gethostbyname' => static fn () => gethostbyname(null),
        'dns_get_record' => static fn () => dns_get_record(null),
    ] as $fn => $call
) {
    try {
        var_export($call());
        echo " $fn\n";
    } catch (Throwable $e) {
        echo get_class($e), " $fn\n";
    }
}

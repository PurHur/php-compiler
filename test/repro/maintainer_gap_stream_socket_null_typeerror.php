<?php

declare(strict_types=1);

// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_stream_socket_null_typeerror.php

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';

foreach (
    [
        'stream_socket_client' => static fn () => stream_socket_client(null),
        'fsockopen' => static fn () => fsockopen(null),
    ] as $fn => $call
) {
    try {
        $call();
        echo $fn, " COERCED\n";
        exit(1);
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";

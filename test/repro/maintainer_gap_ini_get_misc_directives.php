<?php

declare(strict_types=1);

$expected = [
    'allow_url_fopen' => '1',
    'allow_url_include' => '',
    'default_socket_timeout' => '60',
    'auto_detect_line_endings' => '0',
    'default_mimetype' => 'text/html',
    'variables_order' => 'GPCS',
    'request_order' => 'GP',
    'arg_separator.output' => '&',
];

foreach ($expected as $key => $want) {
    $got = ini_get($key);
    if ($got !== $want) {
        echo 'fail: ', $key, '=', var_export($got, true), ' expected ', var_export($want, true), "\n";
        exit(1);
    }
}

echo "ok\n";

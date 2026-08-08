<?php

declare(strict_types=1);

$checks = [
    'upload_max_filesize' => '2M',
    'post_max_size' => '8M',
    'allow_url_fopen' => '1',
    'engine' => '1',
    'zend.assertions' => '1',
    'zend.enable_gc' => '1',
    'zend.exception_ignore_args' => '1',
];

foreach ($checks as $key => $expected) {
    $actual = get_cfg_var($key);
    if ($actual !== $expected) {
        echo 'fail: ', $key, '=', var_export($actual, true), "\n";
        exit(1);
    }
}

echo "ok\n";

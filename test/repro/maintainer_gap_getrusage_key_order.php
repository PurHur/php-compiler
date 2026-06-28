<?php

declare(strict_types=1);

$expected = [
    'ru_oublock',
    'ru_inblock',
    'ru_msgsnd',
    'ru_msgrcv',
    'ru_maxrss',
    'ru_ixrss',
    'ru_idrss',
    'ru_minflt',
    'ru_majflt',
    'ru_nsignals',
    'ru_nvcsw',
    'ru_nivcsw',
    'ru_nswap',
    'ru_utime.tv_usec',
    'ru_utime.tv_sec',
    'ru_stime.tv_usec',
    'ru_stime.tv_sec',
];

$usage = getrusage();
if (!is_array($usage)) {
    echo "fail: getrusage() returned ", var_export($usage, true), "\n";
    exit(1);
}

$keys = array_keys($usage);
if ($keys !== $expected) {
    echo "fail: key order mismatch\n";
    echo 'got:      ', implode(',', $keys), "\n";
    echo 'expected: ', implode(',', $expected), "\n";
    exit(1);
}

echo "ok\n";

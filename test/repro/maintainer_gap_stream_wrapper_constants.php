<?php

declare(strict_types=1);

// Issue #11886 — STREAM_* wrapper flag constants (main/streams/php_stream_wrappers.h).
$names = [
    'STREAM_REPORT_ERRORS',
    'STREAM_CLIENT_ASYNC_CONNECT',
    'STREAM_CLIENT_CONNECT',
    'STREAM_CLIENT_PERSISTENT',
];
$definedCount = 0;
foreach ($names as $name) {
    if (defined($name)) {
        ++$definedCount;
    }
}
echo 'defined_count=', $definedCount, "\n";
echo 'report_errors=', defined('STREAM_REPORT_ERRORS') ? '1' : '0', "\n";
if (4 !== $definedCount) {
    exit(1);
}
if (8 !== STREAM_REPORT_ERRORS || 2 !== STREAM_CLIENT_ASYNC_CONNECT
    || 4 !== STREAM_CLIENT_CONNECT || 1 !== STREAM_CLIENT_PERSISTENT) {
    echo "values_fail\n";
    exit(1);
}
echo "ok\n";

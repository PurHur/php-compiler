<?php

declare(strict_types=1);

// Issue #11828 — STREAM_CAST_* constants (ext/standard/streams.c PHP_MINIT).
if (!defined('STREAM_CAST_AS_STREAM')) {
    echo "fail: STREAM_CAST_AS_STREAM undefined\n";
    exit(1);
}
if (!defined('STREAM_CAST_FOR_SELECT')) {
    echo "fail: STREAM_CAST_FOR_SELECT undefined\n";
    exit(1);
}
if (0 !== STREAM_CAST_AS_STREAM) {
    echo 'fail: STREAM_CAST_AS_STREAM=', STREAM_CAST_AS_STREAM, "\n";
    exit(1);
}
if (3 !== STREAM_CAST_FOR_SELECT) {
    echo 'fail: STREAM_CAST_FOR_SELECT=', STREAM_CAST_FOR_SELECT, "\n";
    exit(1);
}
echo "ok\n";

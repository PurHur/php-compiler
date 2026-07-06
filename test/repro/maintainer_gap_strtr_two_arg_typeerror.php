<?php

try {
    strtr('abc', 1);
    fwrite(STDERR, "fail: int second arg uncaught\n");
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if ($msg !== 'strtr(): Argument #2 ($from) must be of type array, string given') {
        fwrite(STDERR, "fail: int message: {$msg}\n");
        exit(1);
    }
}

try {
    strtr('abc', new stdClass());
    fwrite(STDERR, "fail: object second arg uncaught\n");
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if ($msg !== 'strtr(): Argument #2 ($from) must be of type array|string, stdClass given') {
        fwrite(STDERR, "fail: object message: {$msg}\n");
        exit(1);
    }
}

echo "ok\n";

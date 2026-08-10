<?php

try {
    array_splice((object) [1, 2, 3], 1, 1);
    echo "fail: no exception\n";
    exit(1);
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'could not be passed by reference')) {
        echo 'fail: wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

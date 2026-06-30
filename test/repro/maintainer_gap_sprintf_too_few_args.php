<?php

declare(strict_types=1);

/**
 * Issue #10259 — sprintf/printf vs vsprintf/vprintf too-few-args exception classes.
 */

try {
    sprintf('%d %d', 1);
} catch (Throwable $e) {
    echo 'sprintf: ', get_class($e), "\n";
}
try {
    printf('%d %d', 1);
} catch (Throwable $e) {
    echo 'printf: ', get_class($e), "\n";
}
try {
    vsprintf('%d %d', [1]);
} catch (Throwable $e) {
    echo 'vsprintf: ', get_class($e), "\n";
}
try {
    vprintf('%d %d', [1]);
} catch (Throwable $e) {
    echo 'vprintf: ', get_class($e), "\n";
}

<?php

declare(strict_types=1);

/**
 * Issue #9559 — str_repeat() negative $times must ValueError (ext/standard/string.c).
 */
try {
    str_repeat('x', -1);
    fwrite(STDERR, "fail: no exception\n");
    exit(1);
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

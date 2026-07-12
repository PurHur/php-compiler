<?php

declare(strict_types=1);

/**
 * Issue #4405 — mb_strlen() null encoding + invalid encoding ValueError (ext/mbstring/mbstring.c).
 */
echo mb_strlen('é', 'UTF-8'), "\n";

try {
    var_dump(mb_strlen('hello', null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_dump(mb_strlen('hello', 'NO_SUCH_ENCODING'));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

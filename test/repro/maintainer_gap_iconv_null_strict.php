<?php

declare(strict_types=1);

// Issue #18242 — iconv() null $string must TypeError (ext/iconv/iconv.c Z_PARAM_STR).
try {
    $result = iconv('UTF-8', 'ASCII//TRANSLIT', null);
    echo "result='{$result}'\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

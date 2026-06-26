<?php

declare(strict_types=1);

try {
    $result = http_build_query(['a' => ['x', 'y']], '', '&', PHP_QUERY_RFC1738);
    echo 'ok: ', $result, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

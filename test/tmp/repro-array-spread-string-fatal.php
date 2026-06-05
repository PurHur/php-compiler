<?php
declare(strict_types=1);
try {
    var_export([...[1, 2], ...'ab']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}

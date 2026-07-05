<?php

declare(strict_types=1);

try {
    str_getcsv('a,b', null);
    echo "fail: no exception\n";
    exit(1);
} catch (TypeError $e) {
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

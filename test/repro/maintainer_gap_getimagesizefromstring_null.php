<?php

declare(strict_types=1);

try {
    getimagesizefromstring(null);
    echo "no_exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

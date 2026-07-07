<?php

declare(strict_types=1);

try {
    getimagesize(null);
    echo "getimagesize: uncaught\n";
} catch (TypeError $e) {
    echo 'getimagesize: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'getimagesize: ', get_class($e), ': ', $e->getMessage(), "\n";
}

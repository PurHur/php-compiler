<?php

declare(strict_types=1);

try {
    $r = gethostbyaddr(null);
    echo 'bad:gethostbyaddr:';
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo 'ok:gethostbyaddr:', get_class($e), ':', $e->getMessage(), "\n";
}

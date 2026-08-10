<?php

declare(strict_types=1);

foreach (['ip2long', 'inet_pton', 'inet_ntop'] as $fn) {
    try {
        $r = $fn(null);
        echo 'bad:', $fn, ':';
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo 'ok:', $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

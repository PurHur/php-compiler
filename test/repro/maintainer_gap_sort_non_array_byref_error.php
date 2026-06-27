<?php

declare(strict_types=1);

foreach (['sort', 'ksort'] as $fn) {
    try {
        $fn(new stdClass());
        echo "fail: {$fn}(stdClass) uncaught\n";
        exit(1);
    } catch (\TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array')) {
            echo "fail: {$fn}(stdClass) unexpected: {$e->getMessage()}\n";
            exit(1);
        }
    } catch (\Throwable $e) {
        echo "fail: {$fn}(stdClass) threw ".get_class($e).': '.$e->getMessage()."\n";
        exit(1);
    }
}

echo "ok\n";

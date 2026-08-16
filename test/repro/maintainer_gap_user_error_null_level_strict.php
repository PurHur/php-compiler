<?php
declare(strict_types=1);
// #31464 — strict_types: null $error_level → TypeError
error_reporting(E_ALL);
try {
    user_error('x', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

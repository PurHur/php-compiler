<?php
declare(strict_types=1);

enum Status: int { case Ok = 200; }

try {
    http_response_code(Status::Ok);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

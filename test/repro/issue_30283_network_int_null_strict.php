<?php
declare(strict_types=1);
try {
    getservbyport(null, 'tcp');
    echo "fail-port\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    getprotobynumber(null);
    echo "fail-proto\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

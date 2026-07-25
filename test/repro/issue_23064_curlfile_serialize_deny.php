<?php

declare(strict_types=1);

$f = new CURLFile('/tmp/x');
try {
    echo serialize($f), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:8:"CURLFile":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

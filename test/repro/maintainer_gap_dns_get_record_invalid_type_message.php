<?php

declare(strict_types=1);

try {
    dns_get_record('example.com', 99999);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

<?php

declare(strict_types=1);

try {
    header_register_callback(null);
    echo "uncaught\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function hcb_ok(): void {
    header('X-Hcb-Ok: 1');
}
header_register_callback('hcb_ok');
echo "ok\n";

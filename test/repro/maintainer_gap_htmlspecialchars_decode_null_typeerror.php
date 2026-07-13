<?php
declare(strict_types=1);

try {
    htmlspecialchars_decode(null);
    echo "FAIL expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    echo "ok\n";
    exit(0);
}

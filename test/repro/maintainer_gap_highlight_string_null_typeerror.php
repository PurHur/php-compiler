<?php
declare(strict_types=1);

try {
    highlight_string(null);
    echo "FAIL expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    echo 'ok: '.$e->getMessage()."\n";
    exit(0);
}

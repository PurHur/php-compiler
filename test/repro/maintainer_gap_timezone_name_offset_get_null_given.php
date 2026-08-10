<?php

declare(strict_types=1);

try {
    timezone_name_get(null);
    echo "fail:timezone_name_get\n";
} catch (TypeError $e) {
    echo 'ok:timezone_name_get:', $e->getMessage(), "\n";
}

try {
    timezone_offset_get(null, new DateTime('now'));
    echo "fail:timezone_offset_get\n";
} catch (TypeError $e) {
    echo 'ok:timezone_offset_get:', $e->getMessage(), "\n";
}

<?php

declare(strict_types=1);

try {
    date_offset_get(null);
    echo "fail:date_offset_get\n";
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Argument #1 ($object): Argument #1')) {
        echo 'fail:msg:', $msg, "\n";
    } else {
        echo 'ok:date_offset_get:', $msg, "\n";
    }
}

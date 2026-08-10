<?php

declare(strict_types=1);

try {
    (new DateTime('2020-01-01'))->format(null);
    echo "fail: expected TypeError\n";
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Argument #1 ($format)') && !str_contains($msg, 'Argument #2')) {
        echo 'ok:', $msg, "\n";
    } else {
        echo 'fail:msg:', $msg, "\n";
    }
}

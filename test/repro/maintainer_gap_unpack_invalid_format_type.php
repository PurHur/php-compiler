<?php
declare(strict_types=1);
try {
    unpack('o', 'abcd');
    fwrite(STDERR, "fail: expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'Invalid format type o') ? "ok\n" : $e->getMessage();
}

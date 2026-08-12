<?php

declare(strict_types=1);

/**
 * #30489 — finfo_file() empty $filename → Argument #2 cannot be empty (not generic Path).
 */
$expected = 'finfo_file(): Argument #2 ($filename) cannot be empty';
$finfo = finfo_open(FILEINFO_MIME_TYPE);

try {
    finfo_file($finfo, '');
    fwrite(STDERR, "fail: finfo_file expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'fail: got '.$e->getMessage()."\n");
        exit(1);
    }
    echo $e->getMessage(), "\n";
}

echo "ok\n";

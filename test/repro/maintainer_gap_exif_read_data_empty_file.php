<?php

declare(strict_types=1);

/**
 * #30490 — exif_read_data() empty $file → Argument #1 cannot be empty (not generic Path).
 */
$expected = 'exif_read_data(): Argument #1 ($file) cannot be empty';

try {
    exif_read_data('');
    fwrite(STDERR, "fail: exif_read_data expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'fail: got '.$e->getMessage()."\n");
        exit(1);
    }
    echo $e->getMessage(), "\n";
}

echo "ok\n";

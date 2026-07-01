<?php
declare(strict_types=1);

$compressed = gzcompress('test');
if (false === $compressed) {
    fwrite(STDERR, "FAIL: gzcompress() returned false\n");
    exit(1);
}

$header = bin2hex(substr($compressed, 0, 2));
if ('789c' !== $header) {
    fwrite(STDERR, "FAIL: gzcompress() default header=$header want 789c (level 6)\n");
    exit(1);
}

$explicit = gzcompress('test', 6);
if (false === $explicit) {
    fwrite(STDERR, "FAIL: gzcompress('test', 6) returned false\n");
    exit(1);
}
if ($compressed !== $explicit) {
    fwrite(STDERR, "FAIL: default gzcompress() output differs from level 6\n");
    exit(1);
}

echo "OK header=$header\n";

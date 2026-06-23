<?php

declare(strict_types=1);

// Maintainer gap / issue #10766 — backslash is not a path separator on Unix (ext/standard/basename.c).
$winPath = 'C:\\x\\y';
$base = basename($winPath);
$dir = dirname($winPath);
if ('C:\\x\\y' !== $base) {
    fwrite(STDERR, "basename: expected C:\\x\\y, got {$base}\n");
    exit(1);
}
if ('.' !== $dir) {
    fwrite(STDERR, "dirname: expected ., got {$dir}\n");
    exit(1);
}
if ('b' !== basename('/a/b')) {
    fwrite(STDERR, "basename unix: expected b\n");
    exit(1);
}
echo "PASS\n";

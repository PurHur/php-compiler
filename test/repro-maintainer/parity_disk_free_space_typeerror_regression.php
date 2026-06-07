<?php
// Maintainer repro for #6459 — disk_*_space() TypeError when return is discarded (php-src filestat.c).
try {
    disk_free_space([]);
    fwrite(STDERR, "uncaught array\n");
    exit(1);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    disk_free_space(new stdClass());
    fwrite(STDERR, "uncaught object\n");
    exit(1);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

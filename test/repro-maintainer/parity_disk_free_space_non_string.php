<?php
// Maintainer repro for #4915 — disk_*_space() TypeError for non-string path (php-src filestat.c).
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
echo disk_free_space(sys_get_temp_dir()) !== false ? "ok\n" : "fail\n";

<?php

declare(strict_types=1);

/**
 * FilesystemIterator::rewind() on non-empty dir — pathname key + SplFileInfo current (#13272).
 */

$dir = sys_get_temp_dir().'/fsi_rewind_'.getmypid();
if (!mkdir($dir) && !is_dir($dir)) {
    fwrite(STDERR, "fail: mkdir\n");
    exit(1);
}
file_put_contents($dir.'/one.txt', 'x');

$it = new FilesystemIterator($dir);
$it->rewind();
if (!$it->valid()) {
    fwrite(STDERR, "fail: valid=false after rewind\n");
    exit(1);
}

$key = $it->key();
if (!is_string($key) || !str_ends_with($key, 'one.txt')) {
    fwrite(STDERR, 'fail: key='.var_export($key, true)."\n");
    exit(1);
}

$cur = $it->current();
if (!$cur instanceof SplFileInfo || 'one.txt' !== $cur->getFilename()) {
    fwrite(STDERR, 'fail: current='.get_debug_type($cur)."\n");
    exit(1);
}

unlink($dir.'/one.txt');
rmdir($dir);

echo "ok\n";

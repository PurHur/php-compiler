<?php
declare(strict_types=1);

// Repro for #12629 — DirectoryIterator::valid() (ext/spl/spl_directory.c).
$it = new DirectoryIterator('.');
if (!$it->valid()) {
    echo 'fail: DirectoryIterator::valid() expected true at start', PHP_EOL;
    exit(1);
}
$count = 0;
foreach (new DirectoryIterator('.') as $file) {
    ++$count;
    if ($count >= 1) {
        break;
    }
}
if ($count < 1) {
    echo 'fail: foreach DirectoryIterator drained zero entries', PHP_EOL;
    exit(1);
}
echo 'ok', PHP_EOL;

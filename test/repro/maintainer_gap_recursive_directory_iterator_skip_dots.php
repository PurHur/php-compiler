<?php

declare(strict_types=1);

/**
 * Repro #12892 — RecursiveDirectoryIterator with SKIP_DOTS must iterate without fatal.
 */

$n = iterator_count(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
if ($n <= 0) {
    echo "fail: count={$n}\n";
    exit(1);
}

$skipDotsListedDot = false;
foreach (new FilesystemIterator(__DIR__, FilesystemIterator::SKIP_DOTS) as $entry) {
    $name = $entry->getFilename();
    if ('.' === $name || '..' === $name) {
        $skipDotsListedDot = true;
        break;
    }
}
if ($skipDotsListedDot) {
    echo "fail: SKIP_DOTS iterator listed dot entry\n";
    exit(1);
}

$plainHasDot = false;
foreach (new DirectoryIterator(__DIR__) as $entry) {
    if ('.' === $entry->getFilename()) {
        $plainHasDot = true;
        break;
    }
}
if (!$plainHasDot) {
    echo "fail: plain DirectoryIterator missing dot entry\n";
    exit(1);
}

echo "ok\n";

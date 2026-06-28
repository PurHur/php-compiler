<?php

declare(strict_types=1);

// Repro for #13158 — DirectoryIterator::isDot() / getType() (ext/spl/spl_directory.c).
$it = new DirectoryIterator('.');
if (!method_exists($it, 'isDot')) {
    echo 'fail: method_exists(DirectoryIterator::isDot) false', PHP_EOL;
    exit(1);
}
if (!method_exists($it, 'getType')) {
    echo 'fail: method_exists(DirectoryIterator::getType) false', PHP_EOL;
    exit(1);
}

$foundNonDot = false;
foreach (new DirectoryIterator('.') as $file) {
    if ($file->isDot()) {
        continue;
    }
    $type = $file->getType();
    if (!\in_array($type, ['dir', 'file', 'link', 'fifo', 'char', 'block', 'unknown'], true)) {
        echo 'fail: unexpected getType()='.$type, PHP_EOL;
        exit(1);
    }
    echo $type.':'.$file->getFilename(), PHP_EOL;
    $foundNonDot = true;
    break;
}

if (!$foundNonDot) {
    echo 'fail: no non-dot directory entry found', PHP_EOL;
    exit(1);
}

echo 'ok', PHP_EOL;

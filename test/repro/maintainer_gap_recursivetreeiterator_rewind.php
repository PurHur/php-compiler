<?php

declare(strict_types=1);

/**
 * Issue #13223 — RecursiveTreeIterator::rewind() inner iterator storage (ext/spl/spl_iterators.c).
 */

$dir = sys_get_temp_dir().'/rti_'.getmypid();
mkdir($dir);

// flags=0 — match Zend default (no SKIP_DOTS); VM FilesystemIterator otherwise defaults to SKIP_DOTS (#12629).
$rdi = new RecursiveDirectoryIterator($dir, 0);
$rti = new RecursiveTreeIterator($rdi);

try {
    $rti->rewind();
} catch (Throwable $e) {
    echo 'fail: RecursiveTreeIterator::rewind() threw '.$e->getMessage()."\n";
    rmdir($dir);
    exit(1);
}

echo $rti->valid() ? "valid\n" : "invalid\n";

rmdir($dir);

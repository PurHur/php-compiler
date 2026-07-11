<?php
declare(strict_types=1);

$dir = 'test/compliance/cases/stdlib/glob_onlydir_fixture';
$matches = glob($dir.'/*', GLOB_MARK);
if (false === $matches) {
    echo "fail: glob returned false\n";
    exit(1);
}
foreach ($matches as $entry) {
    if (str_ends_with($entry, '/')) {
        echo "ok\n";
        exit(0);
    }
}
echo "fail: GLOB_MARK produced no trailing-slash directory entries — Zend appends '/' to directories\n";
exit(1);

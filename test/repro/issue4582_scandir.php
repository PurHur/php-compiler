<?php
try {
    scandir(123);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$entries = scandir($dir, '1');
echo implode(',', $entries), "\n";

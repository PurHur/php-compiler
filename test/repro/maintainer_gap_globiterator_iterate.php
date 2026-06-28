<?php
declare(strict_types=1);
$dir = sys_get_temp_dir();
$prefix = 'php-compiler-glob-test-'.getmypid();
$pattern = $dir.'/'.$prefix.'-*.tmp';
$testFile = $dir.'/'.$prefix.'-1.tmp';
file_put_contents($testFile, 'x');
try {
    $it = new GlobIterator($pattern);
    $it->rewind();
    echo 'valid=', (int) $it->valid(), "\n";
    echo 'count=', $it->count(), "\n";
    $found = 0;
    foreach ($it as $path) {
        if (str_contains((string) $path, $prefix)) {
            ++$found;
        }
    }
    echo 'found=', $found, "\n";
    echo $found >= 1 ? "all ok\n" : "fail\n";
} finally {
    @unlink($testFile);
}

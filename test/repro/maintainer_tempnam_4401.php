<?php

$dir = sys_get_temp_dir().'/tempnam-test-'.getmypid();
@mkdir($dir);

$prefix = 'begin_'.str_repeat('x', 300).'_end';
$f = tempnam($dir, $prefix);
echo 'basename-len=', strlen(basename($f)), "\n";
@unlink($f);

$f2 = tempnam('/definitely/does/not/exist', 'pfx');
echo 'fallback-dir=', realpath(dirname($f2)) === realpath(sys_get_temp_dir()) ? 'yes' : 'no', "\n";
@unlink($f2);

try {
    tempnam("a\0b", 'pfx');
    echo "null-byte: no-exception\n";
} catch (Throwable $e) {
    echo 'null-byte:', get_class($e), ':', $e->getMessage(), "\n";
}

@rmdir($dir);

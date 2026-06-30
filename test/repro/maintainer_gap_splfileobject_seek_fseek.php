<?php
declare(strict_types=1);

file_put_contents('/tmp/splfile_seek.txt', "line0\nline1\nline2\nline3\nline4\nline5\n");
$ok = method_exists(SplFileObject::class, 'seek')
    && method_exists(SplFileObject::class, 'fseek')
    && method_exists(SplFileObject::class, 'getCurrentLine');
if (!$ok) {
    echo "methods_missing\n";
    exit(1);
}
$fo = new SplFileObject('/tmp/splfile_seek.txt');
$fo->seek(4);
$current = $fo->current();
$key = $fo->key();
$fseek = $fo->fseek(0);
$afterSeek = $fo->current();
$gcl = $fo->getCurrentLine();
$pass = is_string($current)
    && str_starts_with($current, 'line4')
    && 4 === $key
    && 0 === $fseek
    && is_string($afterSeek)
    && str_starts_with($afterSeek, 'line0')
    && is_string($gcl)
    && str_starts_with($gcl, 'line0');
echo $pass ? "OK\n" : "FAIL\n";
exit($pass ? 0 : 1);

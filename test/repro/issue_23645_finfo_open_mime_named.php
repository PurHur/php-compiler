<?php
/**
 * Repro #23645 — finfo_open / mime_content_type Zend stub named params.
 * php-src: ext/fileinfo/fileinfo.stub.php, ext/standard/file.stub.php
 */
foreach (['finfo_open', 'mime_content_type'] as $fn) {
    echo $fn, ':';
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}
try {
    $f = finfo_open(flags: FILEINFO_MIME_TYPE);
    echo "finfo_ok\n";
    finfo_close($f);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$p = tempnam(sys_get_temp_dir(), 'm');
file_put_contents($p, 'hello');
try {
    echo mime_content_type(filename: $p), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($p);
try {
    finfo_open(options: FILEINFO_MIME_TYPE);
    echo "legacy_options_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}

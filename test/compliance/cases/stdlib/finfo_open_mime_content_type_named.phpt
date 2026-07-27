--TEST--
stdlib finfo_open/mime_content_type Zend stub named params (#23645)
--FILE--
<?php
declare(strict_types=1);

foreach (['finfo_open', 'mime_content_type'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, '=', implode(',', $names), "\n";
}

$f = finfo_open(flags: FILEINFO_MIME_TYPE);
echo is_object($f) ? "finfo_ok\n" : "finfo_bad\n";
if (is_object($f)) {
    finfo_close($f);
}

$p = tempnam(sys_get_temp_dir(), 'mct');
file_put_contents($p, 'hello');
try {
    echo 'mime=', mime_content_type(filename: $p), "\n";
} finally {
    @unlink($p);
}

try {
    finfo_open(options: FILEINFO_MIME_TYPE);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
finfo_open=flags,magic_database
mime_content_type=filename
finfo_ok
mime=text/plain
legacy: Unknown named parameter $options

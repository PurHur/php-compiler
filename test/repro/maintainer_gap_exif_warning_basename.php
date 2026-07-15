<?php
/**
 * #19231 — exif_read_data() "File not supported" warning must use basename, not abspath.
 * php-src: ext/exif/exif.c (php_error_docref + php_basename)
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$msg = null;
set_error_handler(static function (int $errno, string $errstr) use (&$msg): bool {
    $msg = $errstr;

    return true;
});

$path = __FILE__;
@exif_read_data($path);

if (null === $msg) {
    fwrite(STDERR, "fail: no warning\n");
    exit(1);
}

$base = basename($path);
$expected = 'exif_read_data('.$base.'): File not supported';
if ($msg !== $expected) {
    fwrite(STDERR, "fail: got={$msg}\nexpected={$expected}\n");
    exit(1);
}
if (str_contains($msg, dirname($path).'/')) {
    fwrite(STDERR, "fail: absolute path leaked into warning: {$msg}\n");
    exit(1);
}

echo "ok\n";

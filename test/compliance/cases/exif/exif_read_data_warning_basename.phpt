--TEST--
exif exif_read_data() — File not supported warning uses basename not abspath (#19231, ext/exif/exif.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
// Absolute path (cwd-resolved) so the warning would leak dirname without #19231.
$path = realpath('test/compliance/cases/exif/exif_read_data_unsupported_file.phpt');
if (false === $path) {
    echo "skip: missing fixture\n";
    exit(0);
}
$msg = null;
set_error_handler(static function (int $errno, string $errstr) use (&$msg): bool {
    $msg = $errstr;

    return true;
});
@exif_read_data($path);
$base = basename($path);
$expected = 'exif_read_data('.$base.'): File not supported';
echo ($msg === $expected) ? 'basename_ok' : ('basename_fail:'.$msg);
echo "\n";
?>
--EXPECT--
basename_ok

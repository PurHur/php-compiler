--TEST--
exif exif_read_data() — unsupported file emits E_WARNING File not supported (#18573, ext/exif/exif.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$path = 'test/compliance/cases/exif/exif_read_data_unsupported_file.phpt';
$result = @exif_read_data($path);
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'File not supported') ? 'warning_ok' : 'warning_fail';
echo "\n";
?>
--EXPECT--
false
2
warning_ok

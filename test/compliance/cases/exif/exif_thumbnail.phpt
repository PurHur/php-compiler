--TEST--
ext/exif exif_thumbnail() embedded JPEG IFD1 (#20027, php-src ext/exif/exif.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', function_exists('exif_thumbnail') ? 'Y' : 'N', "\n";
$with = __DIR__ . '/test/fixtures/exif/jpeg_with_thumbnail.jpg';
$none = __DIR__ . '/test/fixtures/exif/minimal_exif.jpg';
if (!is_file($with)) {
    $with = dirname(__DIR__, 3) . '/fixtures/exif/jpeg_with_thumbnail.jpg';
    $none = dirname(__DIR__, 3) . '/fixtures/exif/minimal_exif.jpg';
}

$t = exif_thumbnail($with, $w, $h, $type);
echo 'with_ok=', (is_string($t) && strlen($t) > 10) ? 'Y' : 'N', "\n";
echo 'with_soi=', (is_string($t) && strncmp($t, "\xFF\xD8", 2) === 0) ? 'Y' : 'N', "\n";
echo 'dims=', (int) $w, 'x', (int) $h, "\n";
echo 'type_jpeg=', ((int) $type === IMAGETYPE_JPEG) ? 'Y' : 'N', "\n";

$empty = @exif_thumbnail($none);
echo 'none=', ($empty === false) ? 'false' : 'data', "\n";
?>
--EXPECT--
exists=Y
with_ok=Y
with_soi=Y
dims=1x1
type_jpeg=Y
none=false

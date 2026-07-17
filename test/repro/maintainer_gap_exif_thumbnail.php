<?php
echo 'exists=', function_exists('exif_thumbnail') ? 1 : 0, "\n";
if (!function_exists('exif_thumbnail')) {
    echo "MISSING\n";
    exit(0);
}
$with = __DIR__.'/../fixtures/exif/jpeg_with_thumbnail.jpg';
$none = __DIR__.'/../fixtures/exif/minimal_exif.jpg';
$t = exif_thumbnail($with, $w, $h, $type);
echo 'with_ok=', (is_string($t) && strlen($t) > 0) ? 1 : 0, "\n";
echo 'with_soi=', (is_string($t) && strncmp($t, "\xFF\xD8", 2) === 0) ? 1 : 0, "\n";
echo 'w=', (int) $w, ' h=', (int) $h, ' type=', (int) $type, "\n";
$empty = exif_thumbnail($none);
echo 'none=', ($empty === false) ? 'false' : 'data', "\n";

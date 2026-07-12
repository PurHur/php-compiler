--TEST--
stdlib exif_read_data()/exif_imagetype() — JPEG EXIF IFD0 (#3400, ext/exif/exif.c)
--SKIPIF--
<?php
$path = __DIR__ . '/test/fixtures/exif/minimal_exif.jpg';
if (!is_file($path)) {
    die('skip fixture missing');
}
?>
--FILE--
<?php
$path = __DIR__ . '/test/fixtures/exif/minimal_exif.jpg';
echo function_exists('exif_read_data') ? "read_fn\n" : "no-read\n";
echo function_exists('exif_imagetype') ? "type_fn\n" : "no-type\n";
$data = exif_read_data($path);
echo isset($data['Orientation']) ? "orientation_ok\n" : "orientation_fail\n";
echo $data['Orientation'], "\n";
echo exif_imagetype($path) === IMAGETYPE_JPEG ? "imagetype_ok\n" : "imagetype_fail\n";
--EXPECT--
read_fn
type_fn
orientation_ok
6
imagetype_ok

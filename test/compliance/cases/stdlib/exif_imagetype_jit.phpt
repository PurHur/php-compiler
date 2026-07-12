--TEST--
stdlib exif_imagetype() JIT — IMAGETYPE from path (#18181, ext/exif/exif.c)
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
echo exif_imagetype($path) === IMAGETYPE_JPEG ? "imagetype_ok\n" : "imagetype_fail\n";
?>
--EXPECT--
imagetype_ok

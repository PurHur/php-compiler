--TEST--
stdlib exif_tagname() — EXIF IFD tag lookup (#6105, ext/exif/exif.c)
--FILE--
<?php
enum E: int { case A = 99; }
echo function_exists('exif_tagname') ? "fn\n" : "no-fn\n";
echo exif_tagname(0x0112), "\n";
echo exif_tagname(0x010F), "\n";
echo exif_tagname(0x829A), "\n";
var_export(exif_tagname(0xFFFF));
echo "\n";
var_export(exif_tagname(0xFFFE));
echo "\n";
var_export(exif_tagname(-1));
echo "\n";
try {
    exif_tagname(E::A);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fn
Orientation
Make
ExposureTime
'No tag value'
'Computed value'
false
exif_tagname(): Argument #1 ($index) must be of type int, E given

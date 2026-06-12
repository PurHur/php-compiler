--TEST--
stdlib exif_tagname() JIT/AOT (#6105, ext/exif/exif.c)
--FILE--
<?php
enum E: int { case A = 99; }
echo exif_tagname(0x0112), "\n";
echo exif_tagname(0x010F), "\n";
var_export(exif_tagname(0xFFFF));
echo "\n";
var_export(exif_tagname(0xFFFE));
echo "\n";
try {
    exif_tagname(E::A);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Orientation
Make
'No tag value'
'Computed value'
exif_tagname(): Argument #1 ($index) must be of type int, E given

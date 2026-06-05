--TEST--
stdlib image_type_to_extension() — IMAGETYPE_* to extension (#6091, ext/standard/image.c)
--FILE--
<?php
enum E: int { case A = 99; }
echo function_exists('image_type_to_extension') ? "fn\n" : "no-fn\n";
echo defined('IMAGETYPE_JPEG') ? "const\n" : "no-const\n";
echo image_type_to_extension(IMAGETYPE_JPEG), "\n";
echo image_type_to_extension(IMAGETYPE_JPEG, false), "\n";
echo image_type_to_extension(IMAGETYPE_PNG), "\n";
echo image_type_to_extension(IMAGETYPE_GIF), "\n";
echo image_type_to_extension(IMAGETYPE_WEBP), "\n";
echo image_type_to_extension(999) === false ? "unknown\n" : "hit\n";
try {
    $bad = image_type_to_extension(E::A);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fn
const
.jpeg
jpeg
.png
.gif
.webp
unknown
image_type_to_extension(): Argument #1 ($image_type) must be of type int, E given

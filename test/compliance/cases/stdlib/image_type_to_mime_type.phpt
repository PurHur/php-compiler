--TEST--
stdlib image_type_to_mime_type() — IMAGETYPE_* to MIME (#6063, ext/standard/image.c)
--FILE--
<?php
enum E: int { case A = 99; }
echo function_exists('image_type_to_mime_type') ? "fn\n" : "no-fn\n";
echo image_type_to_mime_type(IMAGETYPE_PNG), "\n";
echo image_type_to_mime_type(IMAGETYPE_JPEG), "\n";
echo image_type_to_mime_type(IMAGETYPE_GIF), "\n";
echo image_type_to_mime_type(IMAGETYPE_WEBP), "\n";
echo image_type_to_mime_type(IMAGETYPE_SWF), "\n";
echo image_type_to_mime_type(999), "\n";
echo image_type_to_mime_type(0), "\n";
try {
    $bad = image_type_to_mime_type(E::A);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fn
image/png
image/jpeg
image/gif
image/webp
application/x-shockwave-flash
application/octet-stream
application/octet-stream
image_type_to_mime_type(): Argument #1 ($image_type) must be of type int, E given

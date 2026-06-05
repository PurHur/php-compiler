--TEST--
JIT image_type_to_mime_type() (#6063)
--FILE--
<?php
echo image_type_to_mime_type(IMAGETYPE_PNG), "\n";
echo image_type_to_mime_type(IMAGETYPE_JPEG), "\n";
echo image_type_to_mime_type(IMAGETYPE_BMP), "\n";
echo image_type_to_mime_type(999), "\n";
--EXPECT--
image/png
image/jpeg
image/bmp
application/octet-stream

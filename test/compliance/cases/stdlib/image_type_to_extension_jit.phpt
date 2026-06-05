--TEST--
stdlib image_type_to_extension() JIT/AOT (#6091, ext/standard/image.c)
--FILE--
<?php
echo image_type_to_extension(IMAGETYPE_JPEG), "\n";
echo image_type_to_extension(IMAGETYPE_JPEG, false), "\n";
echo image_type_to_extension(IMAGETYPE_PNG), "\n";
echo image_type_to_extension(999) === false ? "unknown\n" : "hit\n";
--EXPECT--
.jpeg
jpeg
.png
unknown

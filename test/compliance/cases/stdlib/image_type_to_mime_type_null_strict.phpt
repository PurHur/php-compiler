--TEST--
stdlib image_type_to_mime_type() null under strict_types — TypeError (#17040, ext/standard/image.c)
--FILE--
<?php
declare(strict_types=1);
try {
    image_type_to_mime_type(null);
    echo "no exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
image_type_to_mime_type(): Argument #1 ($image_type) must be of type int, null given

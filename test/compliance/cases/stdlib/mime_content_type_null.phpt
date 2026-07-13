--TEST--
stdlib mime_content_type(null) — TypeError not Warning+false (#18711, ext/standard/file.c)
--FILE--
<?php
try {
    mime_content_type(null);
    echo "no_throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mime_content_type(): Argument #1 ($filename) must be of type resource|string, null given

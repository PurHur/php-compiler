--TEST--
stdlib mime_content_type() — backed enum case TypeError (#6196, php-src-strict)
--FILE--
<?php
enum Ep: string { case P = '/tmp/foo.php'; }
try {
    mime_content_type(Ep::P);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mime_content_type(): Argument #1 ($filename_or_stream) must be of type string, Ep given

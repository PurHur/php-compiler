--TEST--
stdlib mime_content_type() JIT — backed enum case TypeError (#6196, php-src-strict)
--SKIPIF--
<?php
enum _PhpcMimeSkipJit: string { case P = 'x'; }
try {
    mime_content_type(_PhpcMimeSkipJit::P);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), '$filename_or_stream')) {
        die('skip Zend PHPT runner — enum TypeError message differs; use VMTest (#6196)');
    }
}
?>
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

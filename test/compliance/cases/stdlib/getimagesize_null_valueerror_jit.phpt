--TEST--
stdlib getimagesize(null) without strict_types JIT — ValueError Path must not be empty (#18235, ext/standard/image.c)
--JIT--
--FILE--
<?php
foreach ([null, ''] as $path) {
    try {
        getimagesize($path);
        echo "miss\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
Path must not be empty
Path must not be empty

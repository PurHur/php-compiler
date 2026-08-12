--TEST--
stdlib getimagesize(null) without strict_types JIT — ValueError Path cannot be empty (#18235 / #29760, ext/standard/image.c)
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
Path cannot be empty
Path cannot be empty

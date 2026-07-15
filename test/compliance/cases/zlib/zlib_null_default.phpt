--TEST--
zlib gzcompress/gzuncompress/gzinflate(null) — TypeError on default profile (#19004, ext/zlib/zlib.c)
--FILE--
<?php
foreach (['gzcompress', 'gzuncompress', 'gzinflate'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
gzcompress(): Argument #1 ($data) must be of type string, null given
gzuncompress(): Argument #1 ($data) must be of type string, null given
gzinflate(): Argument #1 ($data) must be of type string, null given

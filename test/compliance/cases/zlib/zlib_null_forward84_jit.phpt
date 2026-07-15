--TEST--
zlib gzcompress/gzencode/gzdeflate/gzdecode/gzuncompress(null) — TypeError on 8.4 forward profile JIT (#19332, ext/zlib/zlib.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['gzcompress', 'gzencode', 'gzdeflate', 'gzdecode', 'gzuncompress'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo strlen(gzcompress('')), "\n";
?>
--EXPECT--
gzcompress(): Argument #1 ($data) must be of type string, null given
gzencode(): Argument #1 ($data) must be of type string, null given
gzdeflate(): Argument #1 ($data) must be of type string, null given
gzdecode(): Argument #1 ($data) must be of type string, null given
gzuncompress(): Argument #1 ($data) must be of type string, null given
8

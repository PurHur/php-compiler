--TEST--
zlib gzcompress soft-null; gzencode/gzdeflate/gzdecode/gzuncompress TypeError on 8.4 (#21280/#19332)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $r = gzcompress(null);
    echo 'gzcompress: uncaught ', strlen($r), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
foreach (['gzencode', 'gzdeflate', 'gzdecode', 'gzuncompress'] as $fn) {
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
gzcompress: uncaught 8
gzencode(): Argument #1 ($data) must be of type string, null given
gzdeflate(): Argument #1 ($data) must be of type string, null given
gzdecode(): Argument #1 ($data) must be of type string, null given
gzuncompress(): Argument #1 ($data) must be of type string, null given
8

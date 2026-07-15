--TEST--
zlib gzencode/gzdeflate/gzcompress(null) JIT — strict_types call-edge TypeError (#19112, ext/zlib/zlib.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['gzencode', 'gzdeflate', 'gzcompress'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
gzencode: gzencode(): Argument #1 ($data) must be of type string, null given
gzdeflate: gzdeflate(): Argument #1 ($data) must be of type string, null given
gzcompress: gzcompress(): Argument #1 ($data) must be of type string, null given

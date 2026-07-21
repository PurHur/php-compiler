--TEST--
AOT: gzopen(null) — empty-path ValueError on 8.4 forward profile (#21877, ext/zlib/zlib.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    gzopen(null, 'r');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
?>
--EXPECT--
ValueError

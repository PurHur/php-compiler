--TEST--
stdlib gz*() — backed enum case TypeError (#6371, ext/zlib/zlib.c)
--FILE--
<?php
enum E: string { case A = 'hi'; }
$funcs = ['gzcompress', 'gzuncompress', 'gzdeflate', 'gzinflate', 'gzencode', 'gzdecode'];
foreach ($funcs as $fn) {
    try {
        $fn(E::A);
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
gzcompress(): Argument #1 ($data) must be of type string, E given
gzuncompress(): Argument #1 ($data) must be of type string, E given
gzdeflate(): Argument #1 ($data) must be of type string, E given
gzinflate(): Argument #1 ($data) must be of type string, E given
gzencode(): Argument #1 ($data) must be of type string, E given
gzdecode(): Argument #1 ($data) must be of type string, E given

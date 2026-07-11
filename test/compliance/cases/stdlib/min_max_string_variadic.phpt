--TEST--
stdlib min()/max() — string variadic compare (ext/standard/array.c, #11668)
--FILE--
<?php
echo min('a', 'b'), "\n";
echo max('a', 'b'), "\n";
echo min(['a', 'b']), "\n";
echo min(1, 2, 3), "\n";
--EXPECT--
a
b
a
1

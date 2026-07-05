--TEST--
stdlib pathinfo() PATHINFO_* named flags parameter (#9565, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_FILENAME), "\n";
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_EXTENSION), "\n";
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_BASENAME), "\n";
--EXPECT--
baz
txt
baz.txt

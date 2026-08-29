--TEST--
AOT: DirectoryIterator (string) cast matches __toString (#33274 follow-up, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'cast=', (string) $f, "\n";
    echo 'str=', $f->__toString(), "\n";
    break;
}
--EXPECT--
cast=a.txt
str=a.txt

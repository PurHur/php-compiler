--TEST--
AOT: DirectoryIterator getPathname/getPath/__toString (#33274)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if (!$f->isFile()) {
        continue;
    }
    $name = $f->getFilename();
    $path = $f->getPath();
    $pathname = $f->getPathname();
    $asString = $f->__toString();
    echo 'name=', $name,
        ' path=', $path,
        ' pathname=', $pathname,
        ' str=', $asString,
        ' str_is_name=', ($asString === $name) ? '1' : '0',
        ' pathname_ends=', (str_ends_with($pathname, '/'.$name) || str_ends_with($pathname, $name)) ? '1' : '0',
        "\n";
}
--EXPECT--
name=a.txt path=test/fixtures/aot/cases/directoryiterator_27289_fixture pathname=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt str=a.txt str_is_name=1 pathname_ends=1

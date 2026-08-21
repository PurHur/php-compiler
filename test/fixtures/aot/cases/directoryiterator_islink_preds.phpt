--TEST--
AOT: DirectoryIterator isLink/isReadable/isWritable/isExecutable (#33269)
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
    $f->isExecutable();
    echo 'name=', $f->getFilename(),
        ' link=', $f->isLink() ? '1' : '0',
        ' read=', $f->isReadable() ? '1' : '0',
        ' write=', $f->isWritable() ? '1' : '0',
        "\n";
}
--EXPECT--
name=a.txt link=0 read=1 write=1

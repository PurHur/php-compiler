--TEST--
AOT: DirectoryIterator foreach isFile/isDir (#33263)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$nFile = 0;
$nDir = 0;
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if ($f->isFile()) {
        echo 'file=', $f->getFilename(), "\n";
        $nFile++;
    }
    if ($f->isDir()) {
        echo 'dir=', $f->getFilename(), "\n";
        $nDir++;
    }
}
echo "files=$nFile dirs=$nDir\n";
--EXPECT--
file=a.txt
files=1 dirs=0

--TEST--
AOT: DirectoryIterator/FilesystemIterator foreach isDot/getFilename (#27289)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$n = 0;
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo $f->getFilename(), "\n";
    $n++;
}
echo "count=$n\n";
$n2 = 0;
$fi = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
foreach ($fi as $f) {
    echo $f->getFilename(), "\n";
    $n2++;
}
echo "fi_count=$n2\n";
--EXPECT--
a.txt
count=1
a.txt
fi_count=1

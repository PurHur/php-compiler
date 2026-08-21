<?php
// AOT: DirectoryIterator getExtension / getBasename / getType (#33280)
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'fn=', $f->getFilename(), "\n";
    echo 'ext=', $f->getExtension(), "\n";
    echo 'bn=', $f->getBasename(), "\n";
    echo 'bn2=', $f->getBasename('.txt'), "\n";
    echo 'ty=', $f->getType(), "\n";
}

<?php
// AOT: DirectoryIterator getPathname/getPath/__toString (#33274)
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'pn=', $f->getPathname(), "\n";
    echo 'p=', $f->getPath(), "\n";
    echo 's=', (string) $f, "\n";
    echo 'fn=', $f->getFilename(), "\n";
}

<?php
// AOT: DirectoryIterator getPathname/getPath (#33274)
// Note: (string)$f cast still segfaults under AOT; explicit __toString()/getPathname() work.
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'pn=', $f->getPathname(), "\n";
    echo 'p=', $f->getPath(), "\n";
    echo 's=', $f->__toString(), "\n";
    echo 'fn=', $f->getFilename(), "\n";
}

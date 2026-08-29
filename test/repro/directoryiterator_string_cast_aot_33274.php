<?php
// #33274 follow-up — (string)$f on DirectoryIterator must match __toString()/getPathname().
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

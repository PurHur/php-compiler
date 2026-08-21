<?php
/**
 * #33276 — AOT DirectoryIterator getPath/getPathname/getSize must match Zend
 * (no object::getpath / getpathname / getsize abort).
 *
 * Fixture dir is committed under test/fixtures/aot/cases/ (avoid mkdir/rmdir in AOT repro).
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if (!$f->isFile()) {
        continue;
    }
    echo 'name:', $f->getFilename(), "\n";
    echo 'path:', $f->getPath(), "\n";
    echo 'pathname:', $f->getPathname(), "\n";
    echo 'size:', $f->getSize(), "\n";
}

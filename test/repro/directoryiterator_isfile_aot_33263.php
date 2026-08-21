<?php
/**
 * #33263 — AOT DirectoryIterator::isFile()/isDir() must match Zend (no object::isfile abort).
 *
 * Fixture dir is committed under test/fixtures/aot/cases/ (avoid mkdir/rmdir in AOT repro —
 * those hit unrelated IR verify failures under thin standalone).
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if ($f->isFile()) {
        echo 'file:', $f->getFilename(), "\n";
    }
    if ($f->isDir()) {
        echo 'dir:', $f->getFilename(), "\n";
    }
}

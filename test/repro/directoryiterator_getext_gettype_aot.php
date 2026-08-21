<?php
/**
 * #33280 — AOT DirectoryIterator getExtension/getBasename/getType must match Zend
 * (no object::getextension / getbasename / gettype abort).
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
    echo 'ext:', $f->getExtension(), "\n";
    echo 'base:', $f->getBasename(), "\n";
    echo 'base_suf:', $f->getBasename('.txt'), "\n";
    echo 'type:', $f->getType(), "\n";
}

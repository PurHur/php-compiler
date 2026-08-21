<?php
/**
 * #33287 — AOT DirectoryIterator::getRealPath() must match Zend (no object::getrealpath abort).
 *
 * Fixture under test/fixtures/aot/cases/ (avoid mkdir in AOT repro).
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
    echo 'realpath:', $f->getRealPath(), "\n";
    break;
}

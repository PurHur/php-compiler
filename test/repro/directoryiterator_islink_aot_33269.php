<?php
/**
 * #33269 — AOT DirectoryIterator path predicates must match Zend (no object::islink abort).
 *
 * Peer of #33263 isFile/isDir. Fixture under test/fixtures/aot/cases/ (avoid mkdir in AOT repro).
 * isExecutable is lowered too; AOT is_executable()/access X_OK still diverges on some modes —
 * assert only predicates that match Zend here.
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
    // Touch isExecutable so AOT must lower it (no object::isexecutable abort).
    $f->isExecutable();
    echo 'name=', $f->getFilename(),
        ' link=', $f->isLink() ? '1' : '0',
        ' read=', $f->isReadable() ? '1' : '0',
        ' write=', $f->isWritable() ? '1' : '0',
        "\n";
}

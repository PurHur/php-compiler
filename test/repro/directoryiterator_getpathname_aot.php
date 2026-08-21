<?php
/**
 * #33274 — AOT DirectoryIterator getPathname/getPath/__toString must match Zend.
 *
 * Peer of #33263/#33269 path predicates. Fixture under test/fixtures/aot/cases/
 * (avoid mkdir in AOT repro). Use explicit __toString() — untyped (string)$object
 * cast still needs a compile-time class hint (separate from this proxy gap).
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
    echo 'name=', $f->getFilename(),
        ' path=', $f->getPath(),
        ' pathname=', $f->getPathname(),
        ' str=', $f->__toString(),
        "\n";
}

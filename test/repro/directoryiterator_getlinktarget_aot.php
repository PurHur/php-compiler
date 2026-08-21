<?php
/**
 * #33289 — AOT DirectoryIterator getLinkTarget must match Zend (no object::getlinktarget abort).
 *
 * Fixture includes relative symlink link.txt → a.txt (avoid mkdir/symlink under AOT).
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_33289_fixture';
foreach (new DirectoryIterator($dir) as $f) {
    if ($f->isDot() || $f->getFilename() !== 'link.txt') {
        continue;
    }
    echo 'name=', $f->getFilename(),
        ' link=', $f->isLink() ? '1' : '0',
        ' target=', $f->getLinkTarget(),
        "\n";
    break;
}

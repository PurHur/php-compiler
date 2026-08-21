--TEST--
AOT: DirectoryIterator getLinkTarget on relative symlink (#33289)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_33289_fixture';
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
--EXPECT--
name=link.txt link=1 target=a.txt

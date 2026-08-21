<?php
/**
 * #33305 — AOT SplFileInfo::openFile must return SplFileObject (no object::openfile null/abort).
 */
$f = new SplFileInfo('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
$o = $f->openFile('r');
echo 'of_class=', get_class($o), "\n";
echo 'of_name=', $o->getFilename(), "\n";
echo 'of_path=', $o->getPath(), "\n";
echo 'of_pathname=', $o->getPathname(), "\n";

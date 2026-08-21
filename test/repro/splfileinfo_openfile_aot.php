<?php
/**
 * #33305 — AOT SplFileInfo::openFile must return SplFileObject (no abort).
 */
$p = 'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt';
$f = new SplFileInfo($p);
$o = $f->openFile('r');
echo 'class=', get_class($o), "\n";
echo 'name=', $o->getFilename(), "\n";
echo 'pn=', $o->getPathname(), "\n";

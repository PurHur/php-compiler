<?php
/**
 * #33290 — AOT SplFileInfo::__construct must initialise path props (no SIGSEGV).
 */
$f = new SplFileInfo(__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'path=', $f->getPath(), "\n";
echo 'name=', $f->getFilename(), "\n";
echo 'pn=', $f->getPathname(), "\n";

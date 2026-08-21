<?php
/**
 * #33308 — AOT SplFileObject::__construct must init SplFileInfo path props (no SIGSEGV).
 */
$o = new SplFileObject(__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'class=', get_class($o), "\n";
echo 'name=', $o->getFilename(), "\n";
echo 'path=', $o->getPath(), "\n";
echo 'pn=', $o->getPathname(), "\n";

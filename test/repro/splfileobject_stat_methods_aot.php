<?php
/**
 * #33313 — AOT SplFileObject must lower inherited SplFileInfo stat methods.
 */
$o = new SplFileObject(__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'file=', $o->isFile() ? '1' : '0', "\n";
echo 'size=', $o->getSize(), "\n";
echo 'ext=', $o->getExtension(), "\n";
echo 'type=', $o->getType(), "\n";
echo 'base=', $o->getBasename(), "\n";

<?php
/**
 * #33298 — AOT SplFileInfo::getFileInfo/getPathInfo must return SplFileInfo (no object::* abort).
 */
$f = new SplFileInfo(__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
$gi = $f->getFileInfo();
echo 'fi_class=', get_class($gi), "\n";
echo 'fi_name=', $gi->getFilename(), "\n";
echo 'fi_path=', $gi->getPath(), "\n";
$pi = $f->getPathInfo();
echo 'pi_class=', get_class($pi), "\n";
echo 'pi_name=', $pi->getFilename(), "\n";

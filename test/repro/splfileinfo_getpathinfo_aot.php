<?php
/**
 * #33300 — AOT SplFileInfo::getFileInfo / getPathInfo must not abort.
 */
$p = 'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt';
$f = new SplFileInfo($p);
$fi = $f->getFileInfo();
echo 'fi_class=', get_class($fi), "\n";
echo 'fi_name=', $fi->getFilename(), "\n";
echo 'fi_pn=', $fi->getPathname(), "\n";
$pi = $f->getPathInfo();
echo 'pi_class=', get_class($pi), "\n";
echo 'pi_name=', $pi->getFilename(), "\n";
echo 'pi_pn=', $pi->getPathname(), "\n";

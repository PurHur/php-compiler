--TEST--
SPL SplTempFileObject getFilename/getPath empty path + next/valid (#33568, ext/spl/spl_directory.c)
--FILE--
<?php
$f = new SplTempFileObject();
echo 'fn=', $f->getFilename(), ' path=', json_encode($f->getPath()), ' pn=', $f->getPathname(), "\n";
$f->fwrite("a\n");
$f->rewind();
echo (int) $f->valid();
$f->next();
echo (int) $f->valid(), "\n";
$g = new SplFileInfo('php://temp');
echo 'info fn=', $g->getFilename(), ' path=', json_encode($g->getPath()), "\n";
?>
--EXPECT--
fn=php://temp path="" pn=php://temp
11
info fn=temp path="php:\/"

<?php
// SplTempFileObject getFilename/getPath + next/valid without prior current (#33568).
$f = new SplTempFileObject();
echo 'fn=', $f->getFilename(), ' path=', json_encode($f->getPath()), ' pn=', $f->getPathname(), "\n";
$f->fwrite("a\n");
$f->rewind();
echo (int) $f->valid();
$f->next();
echo (int) $f->valid(), "\n";

<?php
// AOT: SplTempFileObject::eof after last successful fgets (#33555).
$f = new SplTempFileObject();
$f->fwrite("a\nb\n");
$f->rewind();
echo (int) $f->eof();
$f->fgets();
$f->fgets();
echo ',', (int) $f->eof(), "\n";

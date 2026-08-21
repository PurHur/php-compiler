<?php
// Mid-file eof must stay false after first fgets (#33555).
$f = new SplTempFileObject();
$f->fwrite("a\nb\nc\n");
$f->rewind();
$f->fgets();
echo (int) $f->eof(), "\n";

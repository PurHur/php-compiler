--TEST--
spl SplTempFileObject::__toString() reads stream contents not pathname (#13610, ext/spl/spl_directory.c)
--FILE--
<?php
$f = new SplTempFileObject();
$f->fwrite('hi');
$f->rewind();
echo (string) $f, "\n";
--EXPECT--
hi

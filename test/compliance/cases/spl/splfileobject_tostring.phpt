--TEST--
spl SplFileObject::__toString() reads stream contents not pathname (#13610, ext/spl/spl_directory.c)
--FILE--
<?php
$f = new SplFileObject('php://memory', 'w+');
$f->fwrite('hi');
$f->rewind();
echo (string) $f, "\n";
--EXPECT--
hi

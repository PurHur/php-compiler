--TEST--
AOT: SplFileObject eof/fgets/fflush + FilesystemIterator::getFlags excess argc (#34984)
--FILE--
<?php
$f = new SplFileObject('/etc/hosts');
$t = new SplTempFileObject();
$t->fwrite("x\n");
$fi = new FilesystemIterator('/tmp');
try {
    $f->eof(1);
    echo "eof:ACCEPTED\n";
} catch (Throwable $e) {
    echo 'eof:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $f->fgets(1);
    echo "fgets:ACCEPTED\n";
} catch (Throwable $e) {
    echo 'fgets:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $t->fflush(1);
    echo "fflush:ACCEPTED\n";
} catch (Throwable $e) {
    echo 'fflush:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $fi->getFlags(1);
    echo "flags:ACCEPTED\n";
} catch (Throwable $e) {
    echo 'flags:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'flags0=', $fi->getFlags(), "\n";
echo 'ok=', is_bool($f->eof()) && true === $t->fflush() ? '1' : '0', "\n";
--EXPECT--
eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given
fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given
fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given
flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
flags0=4096
ok=1

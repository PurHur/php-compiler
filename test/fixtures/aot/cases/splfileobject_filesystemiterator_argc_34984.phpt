--TEST--
AOT: SplFileObject eof/fgets/fflush + FilesystemIterator getFlags excess argc (#34984)
--FILE--
<?php
$f = new SplFileObject('/etc/hosts');
$t = new SplTempFileObject();
$t->fwrite("x\n");
$fi = new FilesystemIterator('/tmp');
try {
    $r = $f->eof(1);
    echo 'eof:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'eof:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = $f->fgets(1);
    echo 'fgets:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'fgets:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = $t->fflush(1);
    echo 'fflush:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'fflush:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = $fi->getFlags(1);
    echo 'flags:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'flags:', get_class($e), ':', $e->getMessage(), "\n";
}
$okLine = $f->fgets();
$okEof = $f->eof();
$okFlush = $t->fflush();
$okFlags = $fi->getFlags();
echo 'ok=', (
    is_string($okLine)
    && is_bool($okEof)
    && true === $okFlush
    && 4096 === $okFlags
) ? '1' : '0', "\n";
--EXPECT--
eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given
fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given
fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given
flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
ok=1

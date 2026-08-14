--TEST--
stdlib: SplFileObject/FilesystemIterator ArgumentCountError JIT (#30937)
--FILE--
<?php
$f = new SplFileObject('/etc/hosts');
$t = new SplTempFileObject();
$t->fwrite("x\n");
$fi = new FilesystemIterator('/tmp');
foreach ([
    'eof' => static fn () => $f->eof(1),
    'fgets' => static fn () => $f->fgets(1),
    'fflush' => static fn () => $t->fflush(1),
    'flags' => static fn () => $fi->getFlags(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$okLine = $f->fgets();
$okEof = $f->eof();
$okFlush = $t->fflush();
$okFlags = $fi->getFlags();
echo 'ok=', (
    is_string($okLine)
    && is_bool($okEof)
    && true === $okFlush
    && is_int($okFlags)
) ? '1' : '0', "\n";
--EXPECT--
eof ArgumentCountError: SplFileObject::eof() expects exactly 0 arguments, 1 given
fgets ArgumentCountError: SplFileObject::fgets() expects exactly 0 arguments, 1 given
fflush ArgumentCountError: SplFileObject::fflush() expects exactly 0 arguments, 1 given
flags ArgumentCountError: FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
ok=1

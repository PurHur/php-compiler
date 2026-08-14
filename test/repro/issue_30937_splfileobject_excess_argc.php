<?php

/**
 * Repro #30937 — SplFileObject eof/fgets/fflush + FilesystemIterator::getFlags excess argc.
 * php-src: ext/spl/spl_directory.c
 */
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
        echo $name, ':', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
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

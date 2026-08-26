<?php

/**
 * Repro #34984 — AOT leftover of #30937: SplFileObject eof/fgets/fflush +
 * FilesystemIterator::getFlags excess argc; zero-arg getFlags returns SKIP_DOTS.
 *
 * Direct try/catch (not arrow closures) — thin AOT ACE inside closures is uncaught
 * without an in-closure handler (peer ArrayIterator::count excess argc).
 *
 * php-src: ext/spl/spl_directory.c
 */
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

$okLine = $f->fgets();
$okEof = $f->eof();
$okFlush = $t->fflush();
$okFlags = $fi->getFlags();
echo 'flags0=', var_export($okFlags, true), "\n";
echo 'ok=', (
    is_string($okLine)
    && is_bool($okEof)
    && true === $okFlush
    && is_int($okFlags)
    && 4096 === $okFlags
) ? '1' : '0', "\n";

<?php
/**
 * SplFileObject residual excess argc → ArgumentCountError (#31008).
 * php-src: ext/spl/spl_directory.c zim_SplFileObject_*
 */
$tmp = tempnam(sys_get_temp_dir(), 'sf');
file_put_contents($tmp, "a,b\n");
$f = new SplFileObject($tmp);
$w = null;
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
show('ftell', static fn () => $f->ftell(1));
show('fstat', static fn () => $f->fstat(1));
show('fpassthru', static fn () => $f->fpassthru(1));
show('fread', static fn () => $f->fread(1, 'x'));
show('fseek', static fn () => $f->fseek(0, SEEK_SET, 'x'));
show('fwrite', static fn () => $f->fwrite('a', null, 'x'));
show('flock', static fn () => $f->flock(LOCK_SH, $w, 'x'));
show('getFlags', static fn () => $f->getFlags(1));
show('setFlags', static fn () => $f->setFlags(0, 'x'));
show('getCsvControl', static fn () => $f->getCsvControl(1));
show('setCsvControl', static fn () => $f->setCsvControl(',', '"', '\\', 'x'));
show('fgetcsv', static fn () => $f->fgetcsv(',', '"', '\\', 'x'));
show('rewind', static fn () => $f->rewind(1));
show('next', static fn () => $f->next(1));
show('key', static fn () => $f->key(1));
show('current', static fn () => $f->current(1));
show('valid', static fn () => $f->valid(1));
show('__toString', static fn () => $f->__toString(1));
show('hasChildren', static fn () => $f->hasChildren(1));
show('getChildren', static fn () => $f->getChildren(1));
$f->rewind();
show('ftell_ok', static fn () => $f->ftell());
show('fread_ok', static fn () => $f->fread(1));
show('getFlags_ok', static fn () => $f->getFlags());
unlink($tmp);

--TEST--
SplFileObject residual I/O/iterator/CSV/flags excess argc JIT (#31008)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'sf');
file_put_contents($tmp, "a,b\n");
$f = new SplFileObject($tmp);
$w = null;
foreach ([
    ['ftell', static fn ($o) => $o->ftell(1)],
    ['fstat', static fn ($o) => $o->fstat(1)],
    ['fpassthru', static fn ($o) => $o->fpassthru(1)],
    ['fread', static fn ($o) => $o->fread(1, 'x')],
    ['fseek', static fn ($o) => $o->fseek(0, SEEK_SET, 'x')],
    ['fwrite', static fn ($o) => $o->fwrite('a', null, 'x')],
    ['flock', static fn ($o) => $o->flock(LOCK_SH, $w, 'x')],
    ['getFlags', static fn ($o) => $o->getFlags(1)],
    ['setFlags', static fn ($o) => $o->setFlags(0, 'x')],
    ['getCsvControl', static fn ($o) => $o->getCsvControl(1)],
    ['setCsvControl', static fn ($o) => $o->setCsvControl(',', '"', '\\', 'x')],
    ['fgetcsv', static fn ($o) => $o->fgetcsv(',', '"', '\\', 'x')],
    ['rewind', static fn ($o) => $o->rewind(1)],
    ['next', static fn ($o) => $o->next(1)],
    ['key', static fn ($o) => $o->key(1)],
    ['current', static fn ($o) => $o->current(1)],
    ['valid', static fn ($o) => $o->valid(1)],
    ['__toString', static fn ($o) => $o->__toString(1)],
    ['hasChildren', static fn ($o) => $o->hasChildren(1)],
    ['getChildren', static fn ($o) => $o->getChildren(1)],
] as [$name, $fn]) {
    try {
        $fn($f);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$f->rewind();
echo 'ftell_ok=', is_int($f->ftell()) ? '1' : '0', "\n";
echo 'fread_ok=', is_string($f->fread(1)) ? '1' : '0', "\n";
echo 'getFlags_ok=', is_int($f->getFlags()) ? '1' : '0', "\n";
unlink($tmp);
?>
--EXPECT--
ftell SplFileObject::ftell() expects exactly 0 arguments, 1 given
fstat SplFileObject::fstat() expects exactly 0 arguments, 1 given
fpassthru SplFileObject::fpassthru() expects exactly 0 arguments, 1 given
fread SplFileObject::fread() expects exactly 1 argument, 2 given
fseek SplFileObject::fseek() expects at most 2 arguments, 3 given
fwrite SplFileObject::fwrite() expects at most 2 arguments, 3 given
flock SplFileObject::flock() expects at most 2 arguments, 3 given
getFlags SplFileObject::getFlags() expects exactly 0 arguments, 1 given
setFlags SplFileObject::setFlags() expects exactly 1 argument, 2 given
getCsvControl SplFileObject::getCsvControl() expects exactly 0 arguments, 1 given
setCsvControl SplFileObject::setCsvControl() expects at most 3 arguments, 4 given
fgetcsv SplFileObject::fgetcsv() expects at most 3 arguments, 4 given
rewind SplFileObject::rewind() expects exactly 0 arguments, 1 given
next SplFileObject::next() expects exactly 0 arguments, 1 given
key SplFileObject::key() expects exactly 0 arguments, 1 given
current SplFileObject::current() expects exactly 0 arguments, 1 given
valid SplFileObject::valid() expects exactly 0 arguments, 1 given
__toString SplFileObject::__toString() expects exactly 0 arguments, 1 given
hasChildren SplFileObject::hasChildren() expects exactly 0 arguments, 1 given
getChildren SplFileObject::getChildren() expects exactly 0 arguments, 1 given
ftell_ok=1
fread_ok=1
getFlags_ok=1

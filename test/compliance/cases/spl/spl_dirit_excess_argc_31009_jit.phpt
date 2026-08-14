--TEST--
DirectoryIterator / FilesystemIterator residual excess argc JIT (#31009)
--FILE--
<?php
$dir = sys_get_temp_dir();
$it = new DirectoryIterator($dir);
foreach ([
    ['rewind', static fn ($o) => $o->rewind(1)],
    ['next', static fn ($o) => $o->next(1)],
    ['key', static fn ($o) => $o->key(1)],
    ['current', static fn ($o) => $o->current(1)],
    ['valid', static fn ($o) => $o->valid(1)],
    ['seek', static fn ($o) => $o->seek(0, 'x')],
] as [$name, $fn]) {
    try {
        $fn($it);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$fi = new FilesystemIterator($dir);
try {
    $fi->setFlags(0, 'x');
    echo "setFlags COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'setFlags ', $e->getMessage(), "\n";
}
$it->rewind();
echo 'valid_ok=', $it->valid() ? '1' : '0', "\n";
$it->seek(0);
echo 'seek_ok=', is_int($it->key()) ? '1' : '0', "\n";
$fi->setFlags(FilesystemIterator::CURRENT_AS_FILEINFO);
echo 'setFlags_ok=', is_int($fi->getFlags()) ? '1' : '0', "\n";
?>
--EXPECT--
rewind DirectoryIterator::rewind() expects exactly 0 arguments, 1 given
next DirectoryIterator::next() expects exactly 0 arguments, 1 given
key DirectoryIterator::key() expects exactly 0 arguments, 1 given
current DirectoryIterator::current() expects exactly 0 arguments, 1 given
valid DirectoryIterator::valid() expects exactly 0 arguments, 1 given
seek DirectoryIterator::seek() expects exactly 1 argument, 2 given
setFlags FilesystemIterator::setFlags() expects exactly 1 argument, 2 given
valid_ok=1
seek_ok=1
setFlags_ok=1

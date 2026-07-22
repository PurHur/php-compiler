--TEST--
GlobIterator getFlags/setFlags/foreach SplFileInfo (#22306)
--FILE--
<?php
$dir = __DIR__.'/test/compliance/cases/stdlib';
$pattern = $dir.'/globiterator_fixture*.tmp';
$it = new GlobIterator($pattern);
echo 'flags=', $it->getFlags(), "\n";
$it->rewind();
echo 'valid=', (int) $it->valid(), "\n";
echo 'count=', $it->count(), "\n";
$found = 0;
$isInfo = 'N';
foreach ($it as $path) {
    $isInfo = ($path instanceof SplFileInfo) ? 'Y' : 'N';
    if (str_contains((string) $path, 'globiterator_fixture')) {
        ++$found;
    }
}
echo 'is_info=', $isInfo, "\n";
echo 'found=', $found, "\n";
$it->setFlags(32);
echo 'flags2=', $it->getFlags(), "\n";
--EXPECT--
flags=0
valid=1
count=1
is_info=Y
found=1
flags2=32

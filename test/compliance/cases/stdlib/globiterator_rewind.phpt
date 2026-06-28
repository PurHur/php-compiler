--TEST--
GlobIterator rewind/valid/count/foreach (issue #13169)
--FILE--
<?php
$dir = __DIR__.'/test/compliance/cases/stdlib';
$pattern = $dir.'/globiterator_fixture*.tmp';
echo class_exists('GlobIterator', false) ? 'yes' : 'no', "\n";
$it = new GlobIterator($pattern);
$it->rewind();
echo 'valid=', (int) $it->valid(), "\n";
echo 'count=', $it->count(), "\n";
$found = 0;
foreach ($it as $path) {
    if (str_contains((string) $path, 'globiterator_fixture')) {
        ++$found;
    }
}
echo 'found=', $found, "\n";
--EXPECT--
yes
valid=1
count=1
found=1

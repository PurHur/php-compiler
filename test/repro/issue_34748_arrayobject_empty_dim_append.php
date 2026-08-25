<?php
/**
 * Repro #34748 (re-#27286) — ArrayObject / ArrayIterator `$o[]=` after construct-with-array.
 * Zend / VM append into `__spl_ht`; thin AOT must not COW-orphan the write.
 */
function dump(string $label, object $o): void
{
    echo $label, ' count=', count($o), ' last=';
    $n = count($o);
    echo $n > 0 ? ($o[$n - 1] ?? 'miss') : 'none';
    echo "\n";
}

$ao = new ArrayObject([1, 2, 3]);
$ao[] = 4;
dump('ao', $ao);

$ai = new ArrayIterator([1, 2, 3]);
$ai[] = 4;
dump('ai', $ai);

$empty = new ArrayObject();
$empty[] = 'x';
$empty[] = 'y';
dump('empty', $empty);

$loop = new ArrayObject([10]);
for ($i = 0; $i < 3; ++$i) {
    $loop[] = $i;
}
echo 'loop count=', count($loop), ' vals=';
for ($i = 0; $i < count($loop); ++$i) {
    echo ($i > 0 ? ',' : ''), $loop[$i];
}
echo "\n";

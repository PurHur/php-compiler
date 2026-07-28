<?php
// #24315 — RecursiveIteratorIterator::getSubIterator OOB/negative → null (php-src-strict)
$it = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3], 4]));
$it->rewind();
while ($it->valid()) {
    if (2 === $it->current()) {
        break;
    }
    $it->next();
}
foreach ([2, 99, -1] as $lvl) {
    $sub = $it->getSubIterator($lvl);
    echo 'lvl=', $lvl, ' => ', null === $sub ? 'null' : get_class($sub), "\n";
}
echo 'valid0=', get_class($it->getSubIterator(0)), "\n";
echo 'valid1=', get_class($it->getSubIterator(1)), "\n";

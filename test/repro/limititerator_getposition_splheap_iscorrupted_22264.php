<?php
// Repro #22264 — LimitIterator::getPosition() + SplHeap::isCorrupted()
$li = new LimitIterator(new ArrayIterator([1, 2, 3, 4]), 1, 2);
echo 'getPosition_exists=', method_exists($li, 'getPosition') ? 'Y' : 'N', PHP_EOL;
$li->rewind();
echo 'pos_after_rewind=', $li->getPosition(), PHP_EOL;
$li->next();
echo 'pos_after_next=', $li->getPosition(), PHP_EOL;

$h = new SplMinHeap();
$h->insert(1);
echo 'isCorrupted_exists=', method_exists($h, 'isCorrupted') ? 'Y' : 'N', PHP_EOL;
echo 'isCorrupted=', $h->isCorrupted() ? 'true' : 'false', PHP_EOL;
echo 'recover=', $h->recoverFromCorruption() ? 'true' : 'false', PHP_EOL;

--TEST--
SplHeap / SplPriorityQueue corruption + extract-flag excess argc (#30998)
--FILE--
<?php
$h = new SplMaxHeap();
$h->insert(1);
foreach (['isCorrupted', 'recoverFromCorruption'] as $m) {
    try {
        $h->$m(1);
        echo "h_$m COERCED\n";
    } catch (ArgumentCountError $e) {
        echo "h_$m ", $e->getMessage(), "\n";
    }
}
echo 'h_isCorrupted_ok=', $h->isCorrupted() ? '1' : '0', "\n";
echo 'h_recover_ok=', $h->recoverFromCorruption() ? '1' : '0', "\n";
$q = new SplPriorityQueue();
$q->insert('a', 1);
try {
    $q->setExtractFlags(SplPriorityQueue::EXTR_BOTH, 99);
    echo "q_setExtractFlags COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'q_setExtractFlags ', $e->getMessage(), "\n";
}
try {
    $q->getExtractFlags(1);
    echo "q_getExtractFlags COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'q_getExtractFlags ', $e->getMessage(), "\n";
}
foreach (['isCorrupted', 'recoverFromCorruption'] as $m) {
    try {
        $q->$m(1);
        echo "q_$m COERCED\n";
    } catch (ArgumentCountError $e) {
        echo "q_$m ", $e->getMessage(), "\n";
    }
}
$q->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
echo 'q_flags_ok=', $q->getExtractFlags(), "\n";
echo 'q_isCorrupted_ok=', $q->isCorrupted() ? '1' : '0', "\n";
echo 'q_recover_ok=', $q->recoverFromCorruption() ? '1' : '0', "\n";
?>
--EXPECT--
h_isCorrupted SplHeap::isCorrupted() expects exactly 0 arguments, 1 given
h_recoverFromCorruption SplHeap::recoverFromCorruption() expects exactly 0 arguments, 1 given
h_isCorrupted_ok=0
h_recover_ok=1
q_setExtractFlags SplPriorityQueue::setExtractFlags() expects exactly 1 argument, 2 given
q_getExtractFlags SplPriorityQueue::getExtractFlags() expects exactly 0 arguments, 1 given
q_isCorrupted SplPriorityQueue::isCorrupted() expects exactly 0 arguments, 1 given
q_recoverFromCorruption SplPriorityQueue::recoverFromCorruption() expects exactly 0 arguments, 1 given
q_flags_ok=3
q_isCorrupted_ok=0
q_recover_ok=1

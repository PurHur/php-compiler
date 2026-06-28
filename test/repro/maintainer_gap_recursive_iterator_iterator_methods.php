<?php

declare(strict_types=1);

/**
 * Issue #13135 — RecursiveIteratorIterator extended methods (ext/spl/spl_iterators.c).
 */

$rii = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3]]));
if (!method_exists($rii, 'getDepth')) {
    echo "fail: RecursiveIteratorIterator::getDepth() missing\n";
    exit(1);
}

$rii->rewind();
if (0 !== $rii->getDepth()) {
    echo 'fail: getDepth after rewind expected 0, got '.$rii->getDepth()."\n";
    exit(1);
}

$rii->setMaxDepth(0);
$rii->rewind();
$out = [];
foreach ($rii as $value) {
    $out[] = $value;
}
if ($out !== [1]) {
    echo 'fail: setMaxDepth(0) expected [1], got '.json_encode($out)."\n";
    exit(1);
}

if (0 !== $rii->getMaxDepth()) {
    echo 'fail: getMaxDepth expected 0 after setMaxDepth(0), got '.var_export($rii->getMaxDepth(), true)."\n";
    exit(1);
}

$inner = $rii->getInnerIterator();
if (!$inner instanceof RecursiveArrayIterator) {
    echo 'fail: getInnerIterator expected RecursiveArrayIterator'."\n";
    exit(1);
}

echo "ok\n";

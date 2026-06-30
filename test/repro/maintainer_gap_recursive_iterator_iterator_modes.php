<?php

declare(strict_types=1);

function collect(int $mode): array
{
    $it = new RecursiveIteratorIterator(
        new RecursiveArrayIterator([1, [2, 3]]),
        $mode
    );
    $out = [];
    foreach ($it as $v) {
        $out[] = $v;
    }

    return $out;
}

$selfFirst = collect(RecursiveIteratorIterator::SELF_FIRST);
$childFirst = collect(RecursiveIteratorIterator::CHILD_FIRST);
$leavesOnly = collect(RecursiveIteratorIterator::LEAVES_ONLY);

$expectSelf = [1, [2, 3], 2, 3];
$expectChild = [1, 2, 3, [2, 3]];
$expectLeaves = [1, 2, 3];

if ($selfFirst !== $expectSelf) {
    fwrite(STDERR, 'SELF_FIRST='.json_encode($selfFirst).' expected '.json_encode($expectSelf)."\n");
    exit(1);
}
if ($childFirst !== $expectChild) {
    fwrite(STDERR, 'CHILD_FIRST='.json_encode($childFirst).' expected '.json_encode($expectChild)."\n");
    exit(1);
}
if ($leavesOnly !== $expectLeaves) {
    fwrite(STDERR, 'LEAVES_ONLY='.json_encode($leavesOnly).' expected '.json_encode($expectLeaves)."\n");
    exit(1);
}
echo "ok\n";

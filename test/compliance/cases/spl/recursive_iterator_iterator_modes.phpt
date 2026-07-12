--TEST--
SPL RecursiveIteratorIterator traversal modes — SELF_FIRST/CHILD_FIRST/LEAVES_ONLY (#17575, ext/spl/spl_iterators.c)
--FILE--
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

echo json_encode(collect(RecursiveIteratorIterator::SELF_FIRST)), "\n";
echo json_encode(collect(RecursiveIteratorIterator::CHILD_FIRST)), "\n";
echo json_encode(collect(RecursiveIteratorIterator::LEAVES_ONLY)), "\n";
--EXPECT--
[1,[2,3],2,3]
[1,2,3,[2,3]]
[1,2,3]

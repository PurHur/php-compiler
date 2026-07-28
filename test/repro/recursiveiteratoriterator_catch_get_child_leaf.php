<?php
/**
 * #24293 — RecursiveIteratorIterator mode=LEAVES_ONLY|CATCH_GET_CHILD (OR'd into mode)
 * must yield non-traversable parents (e.g. stdClass) like Zend; nested arrays stay one element.
 *
 * php-src switch(object->mode) has no default — unknown modes fall through and yield.
 */
$mode = RecursiveIteratorIterator::LEAVES_ONLY | RecursiveIteratorIterator::CATCH_GET_CHILD;

$leaf = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([new stdClass()]), $mode) as $v) {
    $leaf[] = is_object($v) ? get_class($v) : gettype($v);
}
echo 'leaf_count=', count($leaf), ' leaf=', json_encode($leaf), "\n";

$nested = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([[1]]), $mode) as $v) {
    $nested[] = is_array($v) ? 'arr:'.json_encode($v) : var_export($v, true);
}
echo 'nested_count=', count($nested), ' nested=', json_encode($nested), "\n";

// Correct arity: flags=CATCH_GET_CHILD does not change empty-children stdClass (still 0).
$n = 0;
foreach (new RecursiveIteratorIterator(
    new RecursiveArrayIterator([new stdClass()]),
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD
) as $v) {
    $n++;
}
echo "flags_catch_count=$n\n";

// Default LEAVES_ONLY still descends into nested arrays.
$plain = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([[1], 2])) as $v) {
    $plain[] = $v;
}
echo 'plain=', json_encode($plain), "\n";

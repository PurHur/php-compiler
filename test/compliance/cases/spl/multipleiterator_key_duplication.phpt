--TEST--
MultipleIterator::attachIterator duplicate info → Key duplication error (#31552, ext/spl/spl_observer.c)
--FILE--
<?php
$cases = [
    'assoc-str' => [MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC, 'k', 'k'],
    'numeric-str' => [MultipleIterator::MIT_KEYS_NUMERIC, 'k', 'k'],
    'assoc-int' => [MultipleIterator::MIT_KEYS_ASSOC, 0, 0],
    'distinct' => [MultipleIterator::MIT_KEYS_ASSOC, 'a', 'b'],
    'null-null' => [MultipleIterator::MIT_KEYS_ASSOC, null, null],
    'str0-int0' => [MultipleIterator::MIT_KEYS_ASSOC, '0', 0],
];
foreach ($cases as $label => [$flags, $info1, $info2]) {
    $m = new MultipleIterator($flags);
    try {
        $m->attachIterator(new ArrayIterator([1]), $info1);
        $m->attachIterator(new ArrayIterator([2]), $info2);
        echo $label, ' attached count=', $m->countIterators(), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
assoc-str InvalidArgumentException: Key duplication error
numeric-str InvalidArgumentException: Key duplication error
assoc-int InvalidArgumentException: Key duplication error
distinct attached count=2
null-null attached count=2
str0-int0 attached count=2

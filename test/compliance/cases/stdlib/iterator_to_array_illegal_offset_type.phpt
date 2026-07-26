--TEST--
stdlib iterator_to_array() TypeError on array keys (MultipleIterator) (#23573)
--FILE--
<?php
$m = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
$m->attachIterator(new ArrayIterator([10, 20]), 'a');
$m->attachIterator(new ArrayIterator([30, 40]), 'b');
try {
    var_export(iterator_to_array($m));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
// foreach still yields array keys
foreach ($m as $k => $v) {
    echo is_array($k) ? 'array_key' : gettype($k), "\n";
    break;
}
?>
--EXPECT--
TypeError:Illegal offset type
array_key

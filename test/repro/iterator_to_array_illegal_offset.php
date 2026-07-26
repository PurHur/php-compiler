<?php
// #23573 — iterator_to_array rejects array keys (MultipleIterator)
$m = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
$m->attachIterator(new ArrayIterator([10, 20]), 'a');
$m->attachIterator(new ArrayIterator([30, 40]), 'b');
try {
    var_export(iterator_to_array($m));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

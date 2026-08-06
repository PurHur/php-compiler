--TEST--
stdlib gc_disable gc_enable gc_mem_caches Reflection return types (#28022, zend_builtin_functions.stub.php)
--FILE--
<?php
foreach (['gc_disable', 'gc_enable', 'gc_mem_caches', 'gc_collect_cycles', 'gc_enabled'] as $n) {
    $rf = new ReflectionFunction($n);
    echo $n, ' ', $rf->hasReturnType() ? (string) $rf->getReturnType() : '<none>', "\n";
}
?>
--EXPECT--
gc_disable void
gc_enable void
gc_mem_caches int
gc_collect_cycles int
gc_enabled bool

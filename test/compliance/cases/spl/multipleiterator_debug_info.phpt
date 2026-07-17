--TEST--
SPL MultipleIterator::__debugInfo — private storage bag rows {obj,inf} (#20144, ext/spl/spl_iterators.c)
--FILE--
<?php
echo 'has_debug=', var_export(method_exists(MultipleIterator::class, '__debugInfo'), true), "\n";

$mi = new MultipleIterator();
$mi->attachIterator(new ArrayIterator([1]));
$mi->attachIterator(new ArrayIterator([2, 3]), 'x');
$mi->attachIterator(new ArrayIterator([4]), 7);

$info = $mi->__debugInfo();
$bagKey = "\0" . 'SplObjectStorage' . "\0" . 'storage';
$bag = $info[$bagKey] ?? null;
echo 'rows=', is_array($bag) ? count($bag) : 'null', "\n";
echo 'inf0=', array_key_exists('inf', $bag[0] ?? []) ? var_export($bag[0]['inf'], true) : 'missing', "\n";
echo 'inf1=', array_key_exists('inf', $bag[1] ?? []) ? var_export($bag[1]['inf'], true) : 'missing', "\n";
echo 'inf2=', array_key_exists('inf', $bag[2] ?? []) ? var_export($bag[2]['inf'], true) : 'missing', "\n";
echo 'obj1=', is_object($bag[1]['obj'] ?? null) ? get_class($bag[1]['obj']) : 'missing', "\n";

ob_start();
var_dump($mi);
$vd = ob_get_clean();
echo (str_contains($vd, 'object(MultipleIterator)') && str_contains($vd, 'storage')
    && str_contains($vd, ':private') && str_contains($vd, '["obj"]') && str_contains($vd, '["inf"]')
    && str_contains($vd, 'string(1) "x"'))
    ? "var_dump_ok\n" : "var_dump_fail\n";
?>
--EXPECT--
has_debug=true
rows=3
inf0=NULL
inf1='x'
inf2=7
obj1=ArrayIterator
var_dump_ok

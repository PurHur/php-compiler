--TEST--
iterator_apply Reflection iterator/callback/args + named args (#23445, php-src-strict)
--FILE--
<?php
$rf = new ReflectionFunction('iterator_apply');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$it = new ArrayIterator([1]);
var_export(iterator_apply(iterator: $it, callback: fn () => true));
echo "\n";
try {
    iterator_apply(it: $it, function: fn () => true);
    echo "unexpected it ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
iterator,callback,args
1
Error

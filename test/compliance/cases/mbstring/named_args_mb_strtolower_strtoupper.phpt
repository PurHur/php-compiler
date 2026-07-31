--TEST--
mb_strtolower/mb_strtoupper named arguments + Reflection (VM, issue #23657)
--FILE--
<?php
foreach (['mb_strtolower', 'mb_strtoupper'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ':', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
}
echo mb_strtolower(string: 'AbC'), "\n";
echo mb_strtoupper(string: 'AbC'), "\n";
try {
    mb_strtolower(str: 'AbC');
    echo "legacy str accepted\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
mb_strtolower:string,encoding
mb_strtoupper:string,encoding
abc
ABC
Error

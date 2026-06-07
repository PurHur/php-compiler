--TEST--
Generator getReturn() after return yield from closure (issue #6567, Zend/zend_generators.c)
--FILE--
<?php
$sub = function (): Generator {
    yield 1;
    return 'inner';
};
$outer = (function () use ($sub): Generator {
    return yield from $sub();
})();
foreach ($outer as $v) {
    echo $v, "\n";
}
var_export($outer->getReturn());
echo "\n";

function inner(): Generator {
    yield 2;
    return 'named';
}
$named = (function (): Generator {
    return yield from inner();
})();
foreach ($named as $v) {
    echo $v, "\n";
}
var_export($named->getReturn());
echo "\n";
--EXPECT--
1
'inner'
2
'named'

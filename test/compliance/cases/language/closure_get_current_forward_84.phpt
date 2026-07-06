--TEST--
language Closure::getCurrent() forward 8.4 profile compliance (#16989, Zend/zend_closures.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$instanceof = (function (): bool {
    return Closure::getCurrent() instanceof Closure;
})();
if (!$instanceof) {
    echo "fail: instanceof\n";
    exit(1);
}

$c = function (): Closure {
    return Closure::getCurrent();
};
$self = $c();
if (!$self instanceof Closure) {
    echo "fail: not Closure\n";
    exit(1);
}
if ($self !== $c) {
    echo "fail: identity\n";
    exit(1);
}

$top = Closure::getCurrent();
if (null !== $top) {
    echo "fail: top level expected null, got ", get_debug_type($top), "\n";
    exit(1);
}

echo "ok\n";
--EXPECT--
ok

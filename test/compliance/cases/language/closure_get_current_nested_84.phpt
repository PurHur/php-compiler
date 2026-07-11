--TEST--
language Closure::getCurrent() nested closures forward 8.4 profile (#17278, Zend/zend_closures.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$outerId = null;
$outer = function () use (&$outerId): string {
    $inner = function (): bool {
        return Closure::getCurrent() instanceof Closure;
    };
    if (!$inner()) {
        return 'fail: inner instanceof';
    }
    $current = Closure::getCurrent();
    if (!$current instanceof Closure) {
        return 'fail: outer not Closure';
    }
    if (spl_object_id($current) !== $outerId) {
        return 'fail: outer identity';
    }

    return 'ok';
};
$outerId = spl_object_id($outer);

$result = $outer();
if ('ok' !== $result) {
    echo $result, "\n";
    exit(1);
}

echo "ok\n";
--EXPECT--
ok

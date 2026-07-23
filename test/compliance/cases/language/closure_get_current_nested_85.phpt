--TEST--
language Closure::getCurrent() nested closures forward 8.5 profile (#22583, re-#17278, Zend/zend_closures.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
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

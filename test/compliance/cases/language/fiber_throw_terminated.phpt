--TEST--
Fiber throw() on terminated fiber throws catchable FiberError (#9784, Zend/zend_fibers.c)
--FILE--
<?php
declare(strict_types=1);

$f = new Fiber(fn(): int => 1);
$f->start();

try {
    $f->throw(new Exception('terminated'));
} catch (FiberError $e) {
    echo 'caught '.get_class($e)."\n";
}

echo "done\n";
--EXPECT--
caught FiberError
done

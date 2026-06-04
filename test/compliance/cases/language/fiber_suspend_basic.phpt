--TEST--
Fiber::suspend() static call inside fiber callback (zend_fibers.c, #5485)
--FILE--
<?php
$f = new Fiber(function (): void {
    echo "start\n";
    Fiber::suspend('resume');
});
$f->start();
echo "done\n";
--EXPECT--
start
done

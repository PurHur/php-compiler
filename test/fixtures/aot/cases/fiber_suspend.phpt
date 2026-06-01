--TEST--
AOT: Fiber::suspend / start (issue #4019, Zend/zend_fibers.c)
--FILE--
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
--EXPECT--
ok

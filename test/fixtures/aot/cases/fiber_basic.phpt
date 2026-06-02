--TEST--
AOT: Fiber start/resume with echo (issue #4097, Zend/zend_fibers.c)
--FILE--
<?php
$fiber = new Fiber(function (): void {
    echo Fiber::suspend('first');
    echo "done\n";
});
echo $fiber->start(), "\n";
$fiber->resume();
--EXPECT--
first
done

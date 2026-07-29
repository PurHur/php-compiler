--TEST--
Fiber::__construct / WeakReference::create named args (#24592)
--FILE--
<?php
$f = new Fiber(callback: function () {});
echo 'fiber_started=', $f->isStarted() ? '1' : '0', "\n";
$obj = new stdClass();
$wr = WeakReference::create(object: $obj);
echo 'wr_get=', null === $wr->get() ? 'null' : 'object', "\n";
--EXPECT--
fiber_started=0
wr_get=object

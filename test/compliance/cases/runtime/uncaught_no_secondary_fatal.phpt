--TEST--
Uncaught dispatchVmError fatal must not secondary-fatal in ExceptionSupport (#6357)
--FILE--
<?php
$w = WeakReference::create(new stdClass);
--EXPECT_EXIT--
255

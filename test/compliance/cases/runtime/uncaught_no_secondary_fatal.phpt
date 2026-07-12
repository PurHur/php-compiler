--TEST--
Uncaught dispatchVmError fatal must not secondary-fatal in ExceptionSupport (#6357)
--FILE--
<?php
stdClass::undefined();
--EXPECT_EXIT--
255

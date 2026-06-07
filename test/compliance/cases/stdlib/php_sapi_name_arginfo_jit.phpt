--TEST--
stdlib php_sapi_name() JIT — ArgumentCountError when extra arguments (#5985)
--FILE--
<?php
php_sapi_name('extra');
--EXPECT--
--EXPECT_EXIT--
255

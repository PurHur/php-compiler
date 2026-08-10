--TEST--
AOT: unserialize(null) strict_types call-edge TypeError (#29765, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
unserialize(null);
--EXPECT--
--EXPECT_EXIT--
255

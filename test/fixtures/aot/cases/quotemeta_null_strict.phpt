--TEST--
AOT: quotemeta(null) strict_types call-edge TypeError (#19117, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
quotemeta(null);
--EXPECT--
--EXPECT_EXIT--
255

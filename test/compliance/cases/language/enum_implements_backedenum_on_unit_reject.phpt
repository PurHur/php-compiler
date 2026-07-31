--TEST--
Language: unit enum implements BackedEnum — compile fatal (#25946, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum U implements BackedEnum { case A; }
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Non-backed enum U cannot implement interface BackedEnum in %s on line %d

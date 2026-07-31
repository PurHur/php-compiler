--TEST--
Language: unit enum implements UnitEnum — compile fatal (#25946, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum U implements UnitEnum { case A; }
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum U cannot implement previously implemented interface UnitEnum in %s on line %d

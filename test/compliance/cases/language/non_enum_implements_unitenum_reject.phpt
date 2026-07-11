--TEST--
Language: non-enum class implements UnitEnum/BackedEnum — compile fatal (#15447, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
class C implements UnitEnum {}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Non-enum class C cannot implement interface UnitEnum in %s on line %d
--FILE--
<?php
declare(strict_types=1);
class D implements BackedEnum {}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Non-enum class D cannot implement interface BackedEnum in %s on line %d

--TEST--
Language: backed enum implements BackedEnum — compile fatal (#25946, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum S: string implements BackedEnum { case A = "a"; }
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum S cannot implement previously implemented interface BackedEnum in %s on line %d

--TEST--
Language: incompatible trait static properties — Zend fatal message (#7418, zend_traits.c)
--FILE--
<?php
trait T { public static int $x = 1; }
trait U { public static int $x = 2; }
class C { use T, U; }
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: T and U define the same property ($x) in the composition of C. However, the definition differs and is considered incompatible. Class was composed

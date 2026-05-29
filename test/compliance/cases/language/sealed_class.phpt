--TEST--
sealed class: extending without permission is compile-time fatal (#3322)
--FILE--
<?php
class Base {}
sealed class C extends Base {}
class D extends C {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class D cannot extend sealed class C

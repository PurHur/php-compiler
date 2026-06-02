--TEST--
Language: final class constant cannot be overridden (#4455)
--FILE--
<?php
class Base {
    final const X = 1;
}
class Child extends Base {
    const X = 2;
}
echo "unreachable\n";
--EXPECT_EXIT--
255

--TEST--
Language: parent|int legal when class has a parent (#26540 control)
--FILE--
<?php
class Base {}
class A extends Base {
    function f(): parent|int {
        return 1;
    }
}
echo "ok\n";
--EXPECT--
ok

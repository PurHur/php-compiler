--TEST--
Language: self in intersection inside class — compile fatal (#26401)
--FILE--
<?php
class C {
    function f(): self&Stringable {}
}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType self cannot be part of an intersection type%A

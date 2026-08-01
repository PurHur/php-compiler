--TEST--
Language: parent in param/property type without parent — compile fatal (#26540)
--FILE--
<?php
class A {
    public parent|int $x;
}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot use "parent" when current class scope has no parent%A

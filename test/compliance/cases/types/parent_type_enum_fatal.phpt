--TEST--
Language: parent return type on enum — compile fatal (#26540)
--FILE--
<?php
enum E {
    case A;
    public function f(): parent {
        return $this;
    }
}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot use "parent" when current class scope has no parent%A

--TEST--
Language: enum cannot implement Serializable (#26538, Zend/zend_enum.c)
--FILE--
<?php
echo "before\n";
enum E implements Serializable {
    case A;
    public function serialize() { return ""; }
    public function unserialize($d) {}
}
echo "reach\n";
--EXPECTF--
before

Fatal error: Enum E cannot implement the Serializable interface in %s on line %d
--EXPECT_EXIT--
255

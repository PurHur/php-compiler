--TEST--
Language: enum implements interface — missing methods compile fatal (#7353, Zend/zend_enum.c)
--FILE--
<?php
interface Greeter {
    public function greet(): void;
}

enum Status implements Greeter {
    case Open;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum Status must implement 1 abstract private method (Greeter::greet) in %s on line %d

--TEST--
parent::$property fetches and assigns parent static property (issue #3093)
--FILE--
<?php
class Parent_ {
    public static $n = 10;
}
class Child extends Parent_ {
    public function run(): void {
        echo parent::$n;
        parent::$n = 20;
        echo "\n";
        echo parent::$n;
        echo "\n";
    }
}
(new Child())->run();
--EXPECT--
10
20

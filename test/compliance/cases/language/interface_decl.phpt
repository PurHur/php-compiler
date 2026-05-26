--TEST--
interface declaration and class implements (issue #2312)
--FILE--
<?php
interface Greeter {
    public function greet(): string;
}
class Hello implements Greeter {
    public function greet(): string {
        return 'hi';
    }
}
$o = new Hello();
echo $o->greet(), "\n";
echo interface_exists(Greeter::class) ? '1' : '0', "\n";
--EXPECT--
hi
1

--TEST--
language: public instance method call (#58)
--FILE--
<?php
class Greeter {
    public function greet(): string {
        return 'ok';
    }
}
echo (new Greeter())->greet();
--EXPECT--
ok

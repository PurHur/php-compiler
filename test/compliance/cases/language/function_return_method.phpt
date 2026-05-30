--TEST--
language: method return type string (#55)
--FILE--
<?php
class Greeter {
    public function greet(): string {
        return 'hi';
    }
}
echo (new Greeter())->greet();
--EXPECT--
hi

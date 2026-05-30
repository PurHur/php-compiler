--TEST--
Language: valid #[\Override] on interface method (issue #3211)
--FILE--
<?php
interface Greeter {
    public function greet(): string;
}
class Hello implements Greeter {
    #[\Override]
    public function greet(): string {
        return 'hi';
    }
}
echo (new Hello())->greet() . "\n";
--EXPECT--
hi

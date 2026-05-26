--TEST--
simple trait use in class (issue #2314)
--FILE--
<?php
trait Greets {
    public function greet(): string {
        return 'hello';
    }
}
class Speaker {
    use Greets;
}
echo (new Speaker())->greet(), "\n";
--EXPECT--
hello

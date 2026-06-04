--TEST--
AOT class_uses() on class and object (issue #3119)
--FILE--
<?php
trait Greets {
    public function greet(): string {
        return 'hi';
    }
}
class Speaker {
    use Greets;
}
$byClass = class_uses('Speaker');
$byObject = class_uses(new Speaker());
$noAutoload = class_uses(new Speaker(), false);
echo isset($byClass['Greets']) ? '1' : '0';
echo isset($byObject['Greets']) ? '1' : '0';
echo isset($noAutoload['Greets']) ? '1' : '0';
--EXPECT--
111

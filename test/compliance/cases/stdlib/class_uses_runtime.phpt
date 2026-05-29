--TEST--
Stdlib: class_uses() — traits on class and object (VM, #3119)
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
echo isset($byClass['Greets']) ? '1' : '0';
echo isset($byObject['Greets']) ? '1' : '0';
echo class_uses('MissingClass') ? '1' : '0';
echo "\n";
--EXPECT--
110

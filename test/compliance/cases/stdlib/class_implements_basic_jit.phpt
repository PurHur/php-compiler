--TEST--
Stdlib: class_implements() — interfaces on class and object (JIT, #3099)
--FILE--
<?php
interface Greetable {
    public function greet(): string;
}
interface Named extends Greetable {}
class Speaker implements Named {
    public function greet(): string {
        return 'hi';
    }
}
$byClass = class_implements('Speaker');
$byObject = class_implements(new Speaker());
$byIface = class_implements('Named');
echo isset($byClass['Named']) ? '1' : '0';
echo isset($byClass['Greetable']) ? '1' : '0';
echo isset($byObject['Named']) ? '1' : '0';
echo isset($byIface['Named']) ? '1' : '0';
echo class_implements('MissingClass') ? '1' : '0';
echo "\n";
--EXPECT--
11110

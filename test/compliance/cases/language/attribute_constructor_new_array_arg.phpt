--TEST--
Language: attribute constructor new with array ctor arg (#22391)
--FILE--
<?php
class Box {
    public array $a;
    public function __construct(array $a) { $this->a = $a; }
}
#[Attribute]
class A {
    public function __construct(public Box $b) {}
}
#[A(new Box([9]))]
class T {}
$r = (new ReflectionClass(T::class))->getAttributes(A::class)[0]->newInstance();
echo implode(",", $r->b->a), "\n";
var_dump($r->b->a);
--EXPECT--
9
array(1) {
  [0]=>
  int(9)
}

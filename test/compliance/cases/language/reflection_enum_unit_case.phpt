--TEST--
Language: ReflectionEnumUnitCase — enum case attributes + reflection (#3800)
--FILE--
<?php
#[Attribute]
class A {
    public function __construct(public string $v) {}
}

enum E: int {
    #[A("marker")]
    case One = 1;
    case Two = 2;
}

$ref = new ReflectionEnumUnitCase(E::class, "One");
echo $ref->getAttributes()[0]->newInstance()->v, "\n";
echo $ref->getName(), "\n";
var_export($ref->getValue());
echo "\n";
$two = new ReflectionEnumUnitCase(E::class, "Two");
echo $two->getName(), "\n";
var_export($two->getValue());
echo "\n";
--EXPECT--
marker
One
\E::One
Two
\E::Two

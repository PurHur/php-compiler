--TEST--
Language: ReflectionEnum getCase/getCases + isEnumCase case-sensitive keys (#25945, re-#10000)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
    public const X = 9;
}
$r = new ReflectionEnum(E::class);
echo 'hasA=', $r->hasCase('A') ? 'y' : 'n', "\n";
echo 'has_a=', $r->hasCase('a') ? 'y' : 'n', "\n";
$parts = [];
foreach ($r->getCases() as $c) {
    $parts[] = $c->getName().'='.$c->getBackingValue();
}
echo implode(';', $parts), "\n";
echo 'getCaseA=', $r->getCase('A')->getBackingValue(), "\n";
try {
    $r->getCase('a');
    echo "getCase_a: no throw\n";
} catch (ReflectionException $e) {
    echo 'getCase_a: ', $e->getMessage(), "\n";
}
$rc = new ReflectionClass(E::class);
foreach ($rc->getReflectionConstants() as $c) {
    echo 'RCC ', $c->getName(), ' isEnum=', $c->isEnumCase() ? 'y' : 'n', "\n";
}
--EXPECT--
hasA=y
has_a=n
A=1;B=2
getCaseA=1
getCase_a: Case E::a does not exist
RCC A isEnum=y
RCC B isEnum=y
RCC X isEnum=n

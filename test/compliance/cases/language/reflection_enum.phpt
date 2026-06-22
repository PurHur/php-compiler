--TEST--
Language: ReflectionEnum — enum type reflection (#4121)
--FILE--
<?php
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
}
$r = new ReflectionEnum(Suit::class);
echo $r->getName(), "\n";
echo $r->isBacked() ? "backed\n" : "unit\n";
foreach ($r->getCases() as $case) {
    echo $case->getName(), "\n";
}
$hearts = $r->getCase('Hearts');
echo $hearts->getName(), "\n";
var_export($hearts->getValue());
echo "\n";
--EXPECT--
Suit
backed
Hearts
Spades
Hearts
\Suit::Hearts

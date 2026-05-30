--TEST--
Language: UnitEnum::cases() / BackedEnum::cases() (#3308)
--FILE--
<?php
enum Suit: string {
    case Hearts = 'H';
    case Diamonds = 'D';
    case Clubs = 'C';
    case Spades = 'S';
}
enum Status {
    case Pending;
    case Done;
}
$cases = Suit::cases();
echo count($cases);
echo "\n";
echo $cases[0]->name;
echo "\n";
echo $cases[0]->value;
echo "\n";
echo $cases[3]->name;
echo "\n";
echo $cases[3]->value;
echo "\n";
foreach (Suit::cases() as $case) {
    echo $case->name, '=', $case->value, "\n";
}
$unit = Status::cases();
echo count($unit);
echo "\n";
echo $unit[0]->name;
echo "\n";
echo $unit[1]->name;
echo "\n";
--EXPECT--
4
Hearts
H
Spades
S
Hearts=H
Diamonds=D
Clubs=C
Spades=S
2
Pending
Done

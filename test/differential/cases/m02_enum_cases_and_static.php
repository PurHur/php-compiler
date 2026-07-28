<?php
// cases() and an ordinary static method on an enum. Both pass AOT — locks the coverage in.
//
// Worth having next to m03: these prove the enum declaration, the case table and static dispatch on
// an enum are all fine, which is what makes m03's crash specific to from()/tryFrom() rather than
// "enums are unsupported".
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public static function label(): string { return 'suit'; }
}
echo count(Suit::cases()), ' ', Suit::label(), "\n";

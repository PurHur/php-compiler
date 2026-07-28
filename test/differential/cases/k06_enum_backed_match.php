<?php
// FAILS ON AOT — #24163. Compile failure:
//     phpc_match_unhandled_operand_is_object() requires a boxed value in this compiler build
//
// Bounding evidence: match(true) chains compile and run correctly on AOT (corpus i04/j-batch), so
// this is specific to matching on an object/enum operand, not to match() itself. match($this)
// inside an enum method is the shape used by the PHP manual's own enum examples.
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string {
        return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' };
    }
}
echo Suit::Hearts->value, ' ', Suit::from('S')->color(), "\n";

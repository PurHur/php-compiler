<?php
// FAILS ON AOT — #24163 residual, and blocked behind #24208.
//
//     Cannot coerce JIT type __object__* to string for concat
//
// The method is declared : string and every arm returns a string literal, so nothing in it should
// yield an __object__*. The only object in scope is the match OPERAND ($this), which reads as the
// match lowering returning its operand instead of the selected arm.
//
// Bounding evidence: changing the operand to match($this->value) compiles (it then hits #24208 at
// runtime, like m03). So the compile failure is specific to matching on an object operand.
//
// This case needs BOTH #24163 and #24208 to go green; k06 covers the same pair.
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string { return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' }; }
}
echo Suit::Hearts->value, ' ', Suit::from('S')->color(), "\n";

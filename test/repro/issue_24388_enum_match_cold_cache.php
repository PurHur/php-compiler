<?php
// Issue #24388 — AOT match($this) must compile with a cold helper-runtime cache
// (PHI predecessors after JitStringConcat in UnhandledMatchError string formatting).
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string {
        return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' };
    }
}
echo Suit::Hearts->value, ' ', Suit::from('S')->color(), "\n";

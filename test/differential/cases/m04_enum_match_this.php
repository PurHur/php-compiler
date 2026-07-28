<?php
// AOT: match($this) in a backed enum method — fixed by #24183/#24212 (#24163).
// Enum::from() segfault was a separate blocker (#24208 / #24218); both green on master now.
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string { return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' }; }
}
echo Suit::Hearts->value, ' ', Suit::from('S')->color(), "\n";

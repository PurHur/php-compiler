<?php
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string {
        return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' };
    }
}
// Use case fetch (not from()) — BackedEnum::from() is a separate pre-existing AOT segfault.
echo Suit::Hearts->value, ' ', Suit::Spades->color(), "\n";

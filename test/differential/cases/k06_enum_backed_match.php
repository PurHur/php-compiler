<?php
// AOT: match($this) on backed enum — UnhandledMatchError "of type" path must keep the builder
// insert point after GetClassRuntime::ensureLinked (#24163).
// @differential-repeat: 10 enum ->value misread was intermittent before boxed-slot load (#24163)
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string {
        return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' };
    }
}
echo Suit::Hearts->value, ' ', Suit::Spades->color(), "\n";

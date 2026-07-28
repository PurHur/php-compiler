--TEST--
AOT: match($this) inside backed enum method (#24163)
--FILE--
<?php
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function color(): string {
        return match($this) { Suit::Hearts => 'red', Suit::Spades => 'black' };
    }
}
echo Suit::Hearts->value, ' ', Suit::Spades->color(), "\n";
?>
--EXPECT--
H black

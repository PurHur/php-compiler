<?php
// Backed enum case constant and its ->value. Passes AOT — locks the coverage in.
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
$x = Suit::Spades;
echo $x->value, ' ', Suit::Hearts->value . '-' . Suit::Spades->value, "\n";

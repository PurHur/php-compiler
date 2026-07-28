<?php
// #24208: backed enum from()/tryFrom() under AOT — must match Zend.
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
$x = Suit::from('S');
echo $x->value, "\n";

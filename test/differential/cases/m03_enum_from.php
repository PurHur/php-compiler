<?php
// @differential-repeat: 10 heap corruption is intermittent on enum case ->value (#36200)
// #24208: backed enum from()/tryFrom() under AOT — must match Zend.
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
$x = Suit::from('S');
echo $x->value, "\n";

<?php
/** Repro: Suit::cases() returned NULL under AOT — Zend/VM return enum case array. */
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
echo count(Suit::cases()), "\n";

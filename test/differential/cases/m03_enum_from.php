<?php
// FAILS ON AOT — #24208. Segfaults before producing any output.
//
// Bounding evidence: the crash does not need the result — assigning from() to a variable that is
// never read is enough. tryFrom() crashes identically, and so does an int-backed enum. Meanwhile
// cases(), plain static methods and case constants all pass (m01, m02), so this is from()/tryFrom()
// specifically, not enums.
//
// from()/tryFrom() are how a backed enum is built from external input (request param, DB column),
// so this is the most common thing done with one.
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
$x = Suit::from('S');
echo $x->value, "\n";

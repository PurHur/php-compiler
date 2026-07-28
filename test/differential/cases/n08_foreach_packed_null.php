<?php
// #24261 — foreach over a PACKED array containing null must terminate and count all elements.
// at all, killed at the sweep timeout (exit 124 under a 10s cap when run directly).
//
// Bounding evidence, all measured:
//   [1, 2, 3]                        iterates fine (3/3)        <- foreach itself works
//   ['a'=>1, 'b'=>null, 'c'=>3]      iterates fine (3/3)        <- associative + null is fine
//   [1, null, 3] / [null, 2, 3] / [1, 2, null]   all hang       <- position does not matter
//   foreach ($a as $k => $v) echo $k;   emits "0" then hangs    <- advances once, then no progress
//
// So it is packed arrays specifically: the packed representation appears to use a sentinel slot for
// a hole/end marker that a stored null is indistinguishable from. Sibling of #24232, where a null
// element of a packed array READS BACK as int(0) while json_encode() of the same array is correct.
//
// n07 keeps the associative/scalar controls; this case is the packed one.
$a = [1, null, 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo $c, "\n";

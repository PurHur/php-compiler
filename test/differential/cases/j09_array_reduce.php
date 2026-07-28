<?php
// @differential-skip-aot: array_reduce() with a Closure callback is a documented thin-AOT limitation in this build
//
// #24117: this previously failed with an INTERNAL error naming a null method —
// "Call to undefined method ...arrayreducejithelper::null()" — which is what made it a defect
// rather than a boundary. #24155 changed it to decline cleanly and readably, matching array_filter
// (j10): "not supported by thin standalone AOT in this build; use bin/vm.php or bin/jit.php".
//
// Now skip-marked for the same reason j10 is: a clear refusal is a limitation, not a bug, and
// leaving it failing would add a permanently-red AOT line nobody can act on. Kept in the corpus so
// the VM path stays gated and the case is ready if callback support lands.
$a = [1, 2, 3, 4];
echo array_reduce($a, fn($c, $x) => $c + $x, 0), "\n";

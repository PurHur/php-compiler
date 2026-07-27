<?php
// #23840 follow-on coverage. Master already gates plain post-decrement (g08_incdec_post_dec) and
// ++ in a script-scope loop (g08_toplevel_incdec_echo). Neither covers PRE-decrement at script
// scope, nor a run that mixes ++ and -- on the same variable — both of which were also no-ops
// while #23840 was live, and neither of which any case exercises today.
//
// Kept top-level on purpose: the regression only ever appeared at script scope, because every
// pre-existing ++/-- differential case declares its variables inside a function.
$n = 5;
--$n;
echo $n, "\n";

--$n;
--$n;
echo $n, "\n";

$n++;
$n++;
$n--;
echo $n, "\n";

// A decrement that crosses zero: the sign flip is what caught the wrong-slot write during #23840
// triage, where the value stuck at its initial state rather than going negative.
$z = 1;
$z--;
$z--;
$z--;
echo $z, "\n";

// Pre- and post- forms yield the same stored value; only their expression result differs.
$p = 10;
$q = 10;
--$p;
$q--;
echo $p, ' ', $q, "\n";

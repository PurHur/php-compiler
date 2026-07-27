<?php
// @differential-repeat: 10   heap corruption here is intermittent — a single run passes ~70% (#23842)
//
// #23842: script-scope decrements followed by an interpolated echo corrupt the heap. The two-
// variable form was fixed by #23864; three and four variables still fail, at roughly 7/10 and 3/5.
//
// This case exists as much for the marker as for the program. A plain one-run sweep reported this
// shape as passing on most invocations while it was live, which is how #23842 came to be closed
// twice. Ten runs makes it fail reliably.
$a = 5;
$a--;
$b = 9;
$b--;
$c = 2;
$c--;
$d = 7;
$d--;
echo "$a $b $c $d\n";

<?php
// #24011: assigning to a nested array element does not take effect under AOT — $g[1][0] = 9 reads
// back 3, the ORIGINAL value (not garbage), so the read finds the old array and the write went
// elsewhere. May share a root cause with #24010's nested-foreach failure; both write through a
// nested array. FAILS AOT today by design.
$g = [[1, 2], [3, 4]];
$g[1][0] = 9;
echo $g[1][0], "\n";

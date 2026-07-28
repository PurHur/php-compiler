<?php
// Issue #24011: AOT nested array element assignment must persist ($g[1][0]=9).
$g = [[1, 2], [3, 4]];
$g[1][0] = 9;
echo $g[1][0], "\n";

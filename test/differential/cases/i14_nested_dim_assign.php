<?php
// #24011: nested int-key dim assign — AOT must persist into the parent (ZEND_FETCH_DIM_W).
$g = [[1, 2], [3, 4]];
$g[1][0] = 9;
echo $g[1][0], "\n";

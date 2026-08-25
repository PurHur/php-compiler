--TEST--
AOT: ($h = $h . 'A') && ($h = $h . 'B') yields AB (#34558 companion)
--FILE--
<?php
$h = '';
($h = $h . 'A') && ($h = $h . 'B');
echo "h=$h\n";
--EXPECT--
h=AB
--EXPECT_EXIT--
0

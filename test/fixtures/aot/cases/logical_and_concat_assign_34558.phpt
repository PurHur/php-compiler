--TEST--
AOT: ($g .= 'A') && ($g .= 'B') yields AB not B (#34558, re-#24506)
--FILE--
<?php
$g = '';
($g .= 'A') && ($g .= 'B');
echo "g=$g\n";
$h = '';
($h = $h . 'A') && ($h = $h . 'B');
echo "h=$h\n";
--EXPECT--
g=AB
h=AB
--EXPECT_EXIT--
0
